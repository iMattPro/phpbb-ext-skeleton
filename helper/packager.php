<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\skeleton\helper;

use phpbb\config\config;
use phpbb\di\service_collection;
use phpbb\filesystem\filesystem;
use phpbb\skeleton\ext;
use phpbb\skeleton\template\twig\extension\skeleton_version_compare;
use phpbb\template\context;
use phpbb\template\twig\environment;
use phpbb\template\twig\loader;
use phpbb\template\twig\twig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;

class packager
{
	/** @var ContainerInterface */
	protected $phpbb_container;

	/** @var service_collection */
	protected $collection;

	/** @var string */
	protected $root_path;

	/**
	 * Constructor
	 *
	 * @param ContainerInterface $phpbb_container Container
	 * @param service_collection $collection      Service collection
	 * @param string             $root_path       phpBB root path
	 */
	public function __construct(ContainerInterface $phpbb_container, service_collection $collection, $root_path)
	{
		$this->phpbb_container = $phpbb_container;
		$this->collection = $collection;
		$this->root_path = $root_path;
	}

	/**
	 * Get composer dialog values
	 *
	 * @return array
	 */
	public function get_composer_dialog_values()
	{
		return [
			'author'       => [
				'author_name'     => null,
				'author_email'    => null,
				'author_homepage' => null,
				'author_role'     => null,
			],
			'extension'    => [
				'vendor_name'            => null,
				'extension_name'         => null,
				'extension_display_name' => null,
				'extension_description'  => null,
				'extension_version'      => '1.0.0-dev',
				'extension_time'         => date('Y-m-d'),
				'extension_homepage'     => null,
			],
			'requirements' => [
				'php_version'       => '>=' . ext::DEFAULT_SKELETON_PHP,
				'phpbb_version_min' => '>=' . ext::DEFAULT_SKELETON_PHPBB_MIN,
				'phpbb_version_max' => '<' . ext::DEFAULT_SKELETON_PHPBB_MAX,
			],
		];
	}

	/**
	 * Get components dialog values
	 *
	 * @return array
	 */
	public function get_component_dialog_values()
	{
		$components = [];
		foreach ($this->collection as $service)
		{
			/** @var \phpbb\skeleton\skeleton $service */
			$components[$service->get_name()] = [
				'default'      => $service->get_default(),
				'dependencies' => $service->get_dependencies(),
				'files'         => $service->get_files(),
				'group'        => $service->get_group(),
			];
		}

		return $components;
	}

	/**
	 * Create the extension
	 *
	 * @param array $data Extension data
	 */
	public function create_extension($data)
	{
		$ext_path = $this->root_path . 'store/tmp-ext/' . "{$data['extension']['vendor_name']}/{$data['extension']['extension_name']}/";
		$filesystem = new filesystem();
		$filesystem->remove($this->root_path . 'store/tmp-ext');
		$filesystem->mkdir($ext_path);

		$template = $this->get_template_engine();
		$template->set_custom_style('skeletonextension', $this->root_path . 'ext/phpbb/skeleton/skeleton');
		$template->assign_vars([
			'COMPONENT'    => $data['components'],
			'EXTENSION'    => $data['extension'],
			'REQUIREMENTS' => $data['requirements'],
			'AUTHORS'      => $data['authors'],
			'LANGUAGE'     => $this->get_language_version_data($data),
		]);

		$component_data = $this->get_component_dialog_values();
		$skeleton_files[] = [
			'composer.json',
			'license.txt',
			'README.md',
		];

		foreach ($data['components'] as $component => $selected)
		{
			if ($selected && !empty($component_data[$component]['files']))
			{
				$skeleton_files[] = $component_data[$component]['files'];
			}
		}
		$skeleton_files = array_merge(...$skeleton_files);

		foreach ($skeleton_files as $file)
		{
			$body = $template
				->set_filenames(['body' => $file . '.twig'])
				->assign_display('body');
			$filesystem->dump_file($ext_path . str_replace('demo', strtolower($data['extension']['extension_name']), $file), trim($body) . "\n");
		}
	}

	/**
	 * Create the zip archive
	 *
	 * @param array $data Extension data
	 *
	 * @return string
	 */
	public function create_zip($data)
	{
		$tmp_path = $this->root_path . 'store/tmp-ext/';
		$ext_path = "{$data['extension']['vendor_name']}/{$data['extension']['extension_name']}/";
		$zip_path = $tmp_path . "{$data['extension']['vendor_name']}_{$data['extension']['extension_name']}-{$data['extension']['extension_version']}.zip";

		$zip_archive = new \ZipArchive();
		$zip_archive->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		$finder = new Finder();
		$finder->ignoreDotFiles(false)
			->ignoreVCS(false)
			->files()
			->in($tmp_path . $ext_path);

		foreach ($finder as $file)
		{
			$zip_archive->addFile(
				$file->getRealPath(),
				$ext_path . $file->getRelativePath() . DIRECTORY_SEPARATOR . $file->getFilename()
			);
		}

		$zip_archive->close();

		return $zip_path;
	}

	/**
	 * Get the template engine to use for parsing skeleton templates.
	 *
	 * @return twig Template object
	 */
	protected function get_template_engine()
	{
		$config = new config([
			'load_tplcompile' => true,
			'tpl_allow_php'   => false,
			'assets_version'  => null,
		]);

		$container = $this->phpbb_container;
		$path_helper = $container->get('path_helper');
		$filesystem = $container->get('filesystem');
		$cache_dir = $container->getParameter('core.cache_dir');
		$ext_manager = $container->get('ext.manager');

		$is_phpbb_4 = defined('PHPBB_VERSION') &&
			phpbb_version_compare(PHPBB_VERSION, '4.0.0-dev', '>=');

		$args = $is_phpbb_4
			? [
				$container->get('assets.bag'),
				$config,
				$filesystem,
				$path_helper,
				$cache_dir,
				$ext_manager,
				new loader()
			]
			: [
				$config,
				$filesystem,
				$path_helper,
				$cache_dir,
				$ext_manager,
				new loader(new filesystem())
			];

		$environment = new environment(...$args);

		// Custom filter for use by packager to decode greater/less than symbols
		$filter = new \Twig\TwigFilter('skeleton_decode', function ($string) {
			return str_replace(['&lt;', '&gt;'], ['<', '>'], $string);
		}, ['is_safe' => ['html']]);
		$environment->addFilter($filter);

		return new twig(
			$path_helper,
			$config,
			new context(),
			$environment,
			$cache_dir,
			$container->get('user'),
			[
				new skeleton_version_compare()
			]
		);
	}

	/**
	 * Get an array of language class and methods depending on 3.1 or 3.2
	 * compatibility, for use in the skeleton twig templates.
	 *
	 * @param array $data Extension data
	 *
	 * @return array An array of language data
	 */
	protected function get_language_version_data($data)
	{
		$phpbb_31 = false;
		if (isset($data['requirements']['phpbb_version_min']))
		{
			$phpbb_31 = (bool) preg_match('/^\D*3\.1.*$/', $data['requirements']['phpbb_version_min']);
		}

		return [
			'class'		=> $phpbb_31 ? '\phpbb\user' : '\phpbb\language\language',
			'object'	=> $phpbb_31 ? 'user' : 'language',
			'function'	=> $phpbb_31 ? 'add_lang_ext' : 'add_lang',
			'indent'	=> [
				'class'		=> $phpbb_31 ? "\t\t\t" : '',
				'object'	=> $phpbb_31 ? "\t" : '',
			],
		];
	}
}
