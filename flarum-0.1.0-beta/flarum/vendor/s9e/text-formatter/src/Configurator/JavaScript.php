<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator;
use ReflectionClass;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Configurator\Helpers\ConfigHelper;
use s9e\TextFormatter\Configurator\JavaScript\CallbackGenerator;
use s9e\TextFormatter\Configurator\JavaScript\Code;
use s9e\TextFormatter\Configurator\JavaScript\ConfigOptimizer;
use s9e\TextFormatter\Configurator\JavaScript\Dictionary;
use s9e\TextFormatter\Configurator\JavaScript\Encoder;
use s9e\TextFormatter\Configurator\JavaScript\HintGenerator;
use s9e\TextFormatter\Configurator\JavaScript\Minifier;
use s9e\TextFormatter\Configurator\JavaScript\Minifiers\Noop;
use s9e\TextFormatter\Configurator\JavaScript\RegexpConvertor;
use s9e\TextFormatter\Configurator\RendererGenerators\XSLT;
class JavaScript
{
	protected $callbackGenerator;
	protected $config;
	protected $configOptimizer;
	protected $configurator;
	public $encoder;
	public $exportMethods = array(
		'disablePlugin',
		'disableTag',
		'enablePlugin',
		'enableTag',
		'getLogger',
		'parse',
		'preview',
		'setNestingLimit',
		'setParameter',
		'setTagLimit'
	);
	protected $hintGenerator;
	protected $minifier;
	protected $xsl;
	public function __construct(Configurator $configurator)
	{
		$this->callbackGenerator = new CallbackGenerator;
		$this->configOptimizer   = new ConfigOptimizer;
		$this->configurator      = $configurator;
		$this->encoder           = new Encoder;
		$this->hintGenerator     = new HintGenerator;
	}
	public function getMinifier()
	{
		if (!isset($this->minifier))
			$this->minifier = new Noop;
		return $this->minifier;
	}
	public function getParser(array $config = \null)
	{
		$this->configOptimizer->reset();
		$rendererGenerator = new XSLT;
		$this->xsl = $rendererGenerator->getXSL($this->configurator->rendering);
		$this->config = (isset($config)) ? $config : $this->configurator->asConfig();
		ConfigHelper::filterVariants($this->config, 'JS');
		$this->config = $this->callbackGenerator->replaceCallbacks($this->config);
		$src = $this->getHints() . $this->injectConfig($this->getSource());
		$src .= $this->getExports();
		$src = $this->getMinifier()->get($src);
		$src = '(function(){' . $src . '})()';
		return $src;
	}
	public function setMinifier($minifier)
	{
		if (\is_string($minifier))
		{
			$className = __NAMESPACE__ . '\\JavaScript\\Minifiers\\' . $minifier;
			$args = \array_slice(\func_get_args(), 1);
			if (!empty($args))
			{
				$reflection = new ReflectionClass($className);
				$minifier   = $reflection->newInstanceArgs($args);
			}
			else
				$minifier = new $className;
		}
		$this->minifier = $minifier;
		return $minifier;
	}
	protected function encode($value)
	{
		return $this->encoder->encode($value);
	}
	protected function getExports()
	{
		if (empty($this->exportMethods))
			return '';
		$methods = array();
		foreach ($this->exportMethods as $method)
			$methods[] = "'" . $method . "':" . $method;
		return "window['s9e'] = { 'TextFormatter': {" . \implode(',', $methods) . "} }\n";
	}
	protected function getHints()
	{
		$this->hintGenerator->setConfig($this->config);
		$this->hintGenerator->setXSL($this->xsl);
		return $this->hintGenerator->getHints();
	}
	protected function getPluginsConfig()
	{
		$plugins = new Dictionary;
		foreach ($this->config['plugins'] as $pluginName => $pluginConfig)
		{
			if (!isset($pluginConfig['parser']))
				continue;
			unset($pluginConfig['className']);
			if (isset($pluginConfig['quickMatch']))
			{
				$valid = array(
					'[[:ascii:]]',
					'[\\xC0-\\xDF][\\x80-\\xBF]',
					'[\\xE0-\\xEF][\\x80-\\xBF]{2}',
					'[\\xF0-\\xF7][\\x80-\\xBF]{3}'
				);
				$regexp = '#(?>' . \implode('|', $valid) . ')+#';
				if (\preg_match($regexp, $pluginConfig['quickMatch'], $m))
					$pluginConfig['quickMatch'] = $m[0];
				else
					unset($pluginConfig['quickMatch']);
			}
			$globalKeys = array(
				'parser'      => 1,
				'quickMatch'  => 1,
				'regexp'      => 1,
				'regexpLimit' => 1
			);
			$globalConfig = \array_intersect_key($pluginConfig, $globalKeys);
			$localConfig  = \array_diff_key($pluginConfig, $globalKeys);
			if (isset($globalConfig['regexp']) && !($globalConfig['regexp'] instanceof Code))
				$globalConfig['regexp'] = RegexpConvertor::toJS($globalConfig['regexp'], \true);
			$globalConfig['parser'] = new Code(
				'/**
				* @param {!string} text
				* @param {!Array.<Array>} matches
				*/
				function(text, matches)
				{
					/** @const */
					var config=' . $this->encode($localConfig) . ';
					' . $globalConfig['parser'] . '
				}'
			);
			$plugins[$pluginName] = $globalConfig;
		}
		return $this->encode($plugins);
	}
	protected function getRegisteredVarsConfig()
	{
		$registeredVars = $this->config['registeredVars'];
		unset($registeredVars['cacheDir']);
		return $this->encode(new Dictionary($registeredVars));
	}
	protected function getRootContext()
	{
		return $this->encode($this->config['rootContext']);
	}
	protected function getSource()
	{
		$src = '';
		$files = array(
			'Parser/utils.js',
			'Parser/BuiltInFilters.js',
			'Parser/' . (\in_array('getLogger', $this->exportMethods) ? '' : 'Null') . 'Logger.js',
			'Parser/Tag.js',
			'Parser.js'
		);
		if (\in_array('preview', $this->exportMethods, \true))
		{
			$files[] = 'render.js';
			$src .= '/** @const */ var xsl=' . \json_encode($this->xsl) . ";\n";
		}
		foreach ($files as $filename)
		{
			$filepath = __DIR__ . '/../' . $filename;
			$src .= \file_get_contents($filepath) . "\n";
		}
		return $src;
	}
	protected function getTagsConfig()
	{
		$methodName = (\count(\array_intersect(array('disableTag', 'setNestingLimit', 'setTagLimit'), $this->exportMethods))) ? 'optimizeObjectContent' : 'optimizeObject';
		$tags = new Dictionary;
		foreach ($this->config['tags'] as $tagName => $tagConfig)
		{
			if (isset($tagConfig['attributes']))
				$tagConfig['attributes'] = new Dictionary($tagConfig['attributes']);
			$tags[$tagName] = $this->configOptimizer->$methodName($tagConfig);
		}
		return $this->encode($tags);
	}
	protected function injectConfig($src)
	{
		$config = array(
			'plugins'        => $this->getPluginsConfig(),
			'registeredVars' => $this->getRegisteredVarsConfig(),
			'rootContext'    => $this->getRootContext(),
			'tagsConfig'     => $this->getTagsConfig()
		);
		$src = \preg_replace_callback(
			'/(\\nvar (' . \implode('|', \array_keys($config)) . '))(;)/',
			function ($m) use ($config)
			{
				return $m[1] . '=' . $config[$m[2]] . $m[3];
			},
			$src
		);
		$src = $this->configOptimizer->getObjects() . $src;
		$src .= "\n" . \implode("\n", $this->callbackGenerator->getFunctions()) . "\n";
		return $src;
	}
}