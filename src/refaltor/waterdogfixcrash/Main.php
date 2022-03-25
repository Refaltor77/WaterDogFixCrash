<?php

namespace refaltor\waterdogfixcrash;

use JsonMapper_Exception;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\plugin\PluginBase;
use ReflectionClass;

class Main extends PluginBase implements Listener
{
    private array $cache = [];

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function DataPacketReceiveEvent(DataPacketReceiveEvent $event): void{
        $packet = $event->getPacket();
        if ($packet instanceof LoginPacket) {
            try {
                [, $clientData,] = JwtUtils::parse($packet->clientDataJwt);
            } catch (JwtException $e) {
                throw PacketHandlingException::wrap($e);
            }
            if (isset($clientData['Waterdog_XUID']) && isset($clientData['Waterdog_IP'])) {
                $event->getOrigin()->setHandler(new class($this->getServer(), $event->getOrigin(), function (XboxLivePlayerInfo $info) use ($event, $clientData, $packet): void{
                    $class = new ReflectionClass($event->getOrigin());
                    $property = $class->getProperty("info");
                    $property->setAccessible(true);
                    $property->setValue($event->getOrigin(), new XboxLivePlayerInfo($clientData["Waterdog_XUID"], $info->getUsername(), $info->getUuid(), $info->getSkin(), $info->getLocale(), $info->getExtraData()));
                }, function (bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) use ($event): void{
                    $class = new ReflectionClass($event->getOrigin());
                    $method = $class->getMethod("setAuthenticationStatus");
                    $method->setAccessible(true);
                    $method->invoke($event->getOrigin(), $isAuthenticated, $authRequired, $error, $clientPubKey);
                }) extends LoginPacketHandler{
                    protected function parseClientData(string $clientDataJwt): ClientData{
                        try {
                            [, $clientDataClaims,] = JwtUtils::parse($clientDataJwt);
                        } catch (JwtException $e) {
                            throw PacketHandlingException::wrap($e);
                        }
                        $mapper = new \JsonMapper;
                        $mapper->bEnforceMapType = false;
                        $mapper->bExceptionOnMissingData = true;
                        $mapper->bExceptionOnUndefinedProperty = true;
                        try {
                            $properties = array_map(fn(\ReflectionProperty $property) => $property->getName(), (new ReflectionClass(ClientData::class))->getProperties());
                            foreach ($clientDataClaims as $k => $v) {
                                if (!in_array($k, $properties)) {
                                    unset($clientDataClaims[$k]);
                                }
                            }
                            unset($properties);
                            $clientData = $mapper->map($clientDataClaims, new ClientData);
                        } catch (JsonMapper_Exception $e) {
                            throw PacketHandlingException::wrap($e);
                        }
                        return $clientData;
                    }
                });
                if (isset($clientData["Waterdog_IP"])) {
                    $class = new ReflectionClass($event->getOrigin());
                    $property = $class->getProperty("ip");
                    $property->setAccessible(true);
                    $property->setValue($event->getOrigin(), $clientData["Waterdog_IP"]);
                    $this->cache[$event->getOrigin()->getIp()] = $clientData;
                }
            }
        }
    }

    public function onPreLogin(PlayerLoginEvent $event): void {
        if (isset($this->cache[$event->getPlayer()->getNetworkSession()->getIp()]['Waterdog_XUID'])) {
            $class = new ReflectionClass($event->getPlayer());
            $prop = $class->getProperty("xuid");
            $prop->setAccessible(true);
            $prop->setValue($event->getPlayer(), $this->cache[$event->getPlayer()->getNetworkSession()->getIp()]['Waterdog_XUID']);
            unset($this->cache[$event->getPlayer()->getNetworkSession()->getIp()]);
        } else $event->setKickMessage($this->getConfig()->get('kick-not-proxy-message'));
    }
}