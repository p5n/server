<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\AppFramework\Utility;

use ArrayAccess;
use Closure;
use Exception;
use League\Container\Container;
use League\Container\ReflectionContainer;
use OCP\AppFramework\QueryException;
use OCP\IContainer;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

/**
 * SimpleContainer is a simple implementation of a container on basis of League
 */
class SimpleContainer implements ArrayAccess, ContainerInterface, IContainer {

	/** @var Container */
	private $container;

	public function __construct() {
		$this->container = new Container();

		// Enable auto-wiring but also cache the results
		// Ref https://container.thephpleague.com/3.x/auto-wiring/
		$this->container->delegate(
			(new ReflectionContainer())->cacheResolutions()
		);
	}

	public function get($id) {
		return $this->container->get($id);
	}

	public function has($id): bool {
		return $this->container->has($id);
	}

	/**
	 * @param ReflectionClass $class the class to instantiate
	 * @return \stdClass the created class
	 * @suppress PhanUndeclaredClassInstanceof
	 */
	private function buildClass(ReflectionClass $class) {
		$constructor = $class->getConstructor();
		if ($constructor === null) {
			return $class->newInstance();
		}

		return $class->newInstanceArgs(array_map(function (ReflectionParameter $parameter) {
			$parameterClass = $parameter->getClass();

			// try to find out if it is a class or a simple parameter
			if ($parameterClass === null) {
				$resolveName = $parameter->getName();
			} else {
				$resolveName = $parameterClass->name;
			}

			try {
				$builtIn = $parameter->hasType() && $parameter->getType()->isBuiltin();
				return $this->query($resolveName, !$builtIn);
			} catch (QueryException $e) {
				// Service not found, use the default value when available
				if ($parameter->isDefaultValueAvailable()) {
					return $parameter->getDefaultValue();
				}

				if ($parameterClass !== null) {
					$resolveName = $parameter->getName();
					return $this->query($resolveName);
				}

				throw $e;
			}
		}, $constructor->getParameters()));
	}

	/**
	 * If a parameter is not registered in the container try to instantiate it
	 * by using reflection to find out how to build the class
	 * @param string $name the class name to resolve
	 * @return \stdClass
	 * @throws QueryException if the class could not be found or instantiated
	 */
	public function resolve($name) {
		$baseMsg = 'Could not resolve ' . $name . '!';
		try {
			$class = new ReflectionClass($name);
			if ($class->isInstantiable()) {
				return $this->buildClass($class);
			} else {
				throw new QueryException($baseMsg .
					' Class can not be instantiated');
			}
		} catch (ReflectionException $e) {
			throw new QueryException($baseMsg . ' ' . $e->getMessage());
		}
	}

	public function query(string $name, bool $autoload = true) {
		$name = $this->sanitizeName($name);
		if ($this->offsetExists($name)) {
			return $this->offsetGet($name);
		}

		if ($autoload) {
			$object = $this->resolve($name);
			$this->registerService($name, function () use ($object) {
				return $object;
			});
			return $object;
		}

		throw new QueryException('Could not resolve ' . $name . '!');
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function registerParameter($name, $value) {
		$this[$name] = $value;
	}

	/**
	 * The given closure is call the first time the given service is queried.
	 * The closure has to return the instance for the given service.
	 * Created instance will be cached in case $shared is true.
	 *
	 * @param string $name name of the service to register another backend for
	 * @param Closure $closure the closure to be called on service creation
	 * @param bool $shared
	 */
	public function registerService($name, Closure $closure, $shared = true) {
		$this->container->add(
			$this->sanitizeName($name),
			function() use ($closure) {
				// The old Pimple factory always passed itself, so we have to keep this for BC
				return $closure($this);
			},
			$shared
		);
	}

	/**
	 * Shortcut for returning a service from a service under a different key,
	 * e.g. to tell the container to return a class when queried for an
	 * interface
	 * @param string $alias the alias that should be registered
	 * @param string $target the target that should be resolved instead
	 */
	public function registerAlias($alias, $target) {
		// TODO: possibly no need for factory closure
		$this->container->add($alias, function() use ($target) {
			return $this->container->get($target);
		}, true);
	}

	/*
	 * @param string $name
	 * @return string
	 */
	protected function sanitizeName($name) {
		if (isset($name[0]) && $name[0] === '\\') {
			return ltrim($name, '\\');
		}
		return $name;
	}

	/**
	 * @deprecated 20.0.0 use \Psr\Container\ContainerInterface::has
	 */
	public function offsetExists($id) {
		return $this->container->has($id);
	}

	/**
	 * @deprecated 20.0.0 use \Psr\Container\ContainerInterface::get
	 */
	public function offsetGet($id) {
		return $this->container->get($id);
	}

	/**
	 * @deprecated 20.0.0 use \OCP\IContainer::registerService
	 */
	public function offsetSet($id, $service) {
		$this->container->add($id, $service);
	}

	/**
	 * @deprecated 20.0.0
	 */
	public function offsetUnset($offset) {
		throw new Exception("Not implemented");
	}
}
