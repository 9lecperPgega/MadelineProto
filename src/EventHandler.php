<?php

declare(strict_types=1);

/**
 * EventHandler module.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Sync\LocalMutex;
use danog\MadelineProto\Db\DbPropertiesTrait;
use Generator;
use ReflectionClass;
use ReflectionMethod;
use Revolt\EventLoop;
use Webmozart\Assert\Assert;

/**
 * Event handler.
 */
abstract class EventHandler extends AbstractAPI
{
    use DbPropertiesTrait {
        DbPropertiesTrait::initDb as private internalInitDb;
    }
    /**
     * Start MadelineProto and the event handler.
     *
     * Also initializes error reporting, catching and reporting all errors surfacing from the event loop.
     *
     * @param string $session Session name
     * @param SettingsAbstract $settings Settings
     */
    final public static function startAndLoop(string $session, SettingsAbstract $settings): void
    {
        $API = new API($session, $settings);
        $API->startAndLoopInternal(static::class);
    }
    /**
     * Start MadelineProto as a bot and the event handler.
     *
     * Also initializes error reporting, catching and reporting all errors surfacing from the event loop.
     *
     * @param string $session Session name
     * @param string $token Bot token
     * @param SettingsAbstract $settings Settings
     */
    final public static function startAndLoopBot(string $session, string $token, SettingsAbstract $settings): void
    {
        $API = new API($session, $settings);
        $API->botLogin($token);
        $API->startAndLoopInternal(static::class);
    }
    /** @internal */
    final protected function reconnectFull(): bool
    {
        return true;
    }
    /**
     * Whether the event handler was started.
     */
    private bool $startedInternal = false;
    private ?LocalMutex $startMutex = null;
    private ?DeferredFuture $startDeferred = null;
    /**
     * Start method handler.
     *
     * @internal
     */
    final public function internalStart(APIWrapper $MadelineProto, array $pluginsPrev, array &$pluginsNew, bool $main = true): ?array
    {
        if ($this->startedInternal) {
            return null;
        }
        $this->startMutex ??= new LocalMutex;
        $this->startDeferred ??= new DeferredFuture;
        $startDeferred = $this->startDeferred;
        $lock = $this->startMutex->acquire();
        try {
            $this->wrapper = $MadelineProto;
            $this->exportNamespaces();

            if (isset(static::$dbProperties)) {
                $this->internalInitDb($this->wrapper->getAPI());
            }
            if ($main) {
                $this->setReportPeers(Tools::call($this->getReportPeers())->await());
            }
            if (\method_exists($this, 'onStart')) {
                $r = $this->onStart();
                if ($r instanceof Generator) {
                    $r = Tools::consumeGenerator($r);
                }
                if ($r instanceof Future) {
                    $r = $r->await();
                }
            }
            if ($main) {
                $this->setReportPeers(Tools::call($this->getReportPeers())->await());
            }

            $constructors = $this->getTL()->getConstructors();
            $methods = [];
            $has_any = false;
            foreach ((new ReflectionClass($this))->getMethods(ReflectionMethod::IS_PUBLIC) as $methodRefl) {
                $method = $methodRefl->getName();
                if ($method === 'onAny') {
                    $has_any = true;
                    continue;
                }
                $method_name = \lcfirst(\substr($method, 2));
                if (($constructor = $constructors->findByPredicate($method_name)) && $constructor['type'] === 'Update') {
                    $methods[$method_name] = $this->$method(...);
                }
            }
            if ($has_any) {
                foreach ($constructors->by_id as $constructor) {
                    if ($constructor['type'] === 'Update' && !isset($methods[$constructor['predicate']])) {
                        $methods[$constructor['predicate']] = $this->onAny(...);
                    }
                }
            }

            $plugins = \array_values(\array_unique($this->getPlugins()));
            Assert::allSubclassOf($plugins, self::class);
            foreach ($plugins as $class => $_) {
                $plugin = $pluginsPrev[$class] ?? $pluginsNew[$class] ?? new $class;
                $pluginsNew[$class] = $plugin;
                foreach ($plugin->internalStart($MadelineProto, $pluginsPrev, $pluginsNew, false) ?? [] as $update => $method) {
                    if (isset($methods[$update])) {
                        $oldMethod = $methods[$update];
                        $methods[$update] = function (array $update) use ($oldMethod, $method): void {
                            EventLoop::queue(function () use ($update, $method): void {
                                $r = $method($update);
                                if ($r instanceof Generator) {
                                    Tools::consumeGenerator($r);
                                }
                            });
                            $r = $oldMethod($update);
                            if ($r instanceof Generator) {
                                Tools::consumeGenerator($r);
                            }
                        };
                    }
                }
            }

            $this->startedInternal = true;
            return $methods;
        } finally {
            $this->startDeferred = null;
            $startDeferred->complete();
            $lock->release();
        }
    }
    /**
     * @internal
     */
    final public function waitForInternalStart(): ?Future
    {
        if (!$this->startedInternal && !$this->startDeferred) {
            $this->startDeferred = new DeferredFuture;
        }
        return $this->startDeferred?->getFuture();
    }
    /**
     * Get peers where to send error reports.
     *
     * @return string|int|array<string|int>
     */
    public function getReportPeers()
    {
        return [];
    }
    /**
     * Obtain a list of plugin event handlers.
     *
     * @return array<class-string<EventHandler>>
     */
    public function getPlugins(): array
    {
        return [];
    }
}
