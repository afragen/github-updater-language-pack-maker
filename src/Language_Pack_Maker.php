<?php

/**
 * Language Pack Maker
 *
 * A lightweight class to combine mo/po/json files into language packs
 * and create a `language-pack.json` file containing update data.
 *
 * @author    Andy Fragen
 * @license   MIT
 * @link      https://github.com/afragen/language-pack-maker
 */

namespace Fragen\Language_Pack_Maker;

use Gettext\Translations;
use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;
use WP_CLI\I18n;
use WP_CLI\I18n\MakeJsonCommand;
//use function WP_CLI\I18n\MakeJsonCommand\make_json as make_json;

/**
 * Class Language_Pack_Maker
 */
class Language_Pack_Maker {
	/**
	 * List of files in specified directory.
	 *
	 * @var array
	 */
	private $directory_list;

	/**
	 * List of available translations.
	 *
	 * @var array
	 */
	private $translations;

	/**
	 * Array of .mo/.po/.json files for each translation.
	 *
	 * @var array
	 */
	private $packages;

	/**
	 * Shortcut to root directory of languages repo.
	 *
	 * @var string
	 */
	private $root_dir;

	/**
	 * Shortcut to `/languages` directory.
	 *
	 * @var string
	 */
	private $language_files_dir;

	/**
	 * Shortcut to `/tmp` directory.
	 *
	 * @var string
	 */
	private $temp_language_files_dir;

	/**
	 * Shortcut to `/packages` directory, where zipfiles will live.
	 *
	 * @var string
	 */
	private $packages_dir;

	/**
	 * Language_Pack_Maker constructor.
	 */
	public function __construct() {
		$this->root_dir                = dirname( __DIR__, 4 );
		$this->language_files_dir      = $this->root_dir . '/languages';
		$this->temp_language_files_dir = $this->root_dir . '/tmp';
		$this->packages_dir            = $this->root_dir . '/packages';
		@mkdir( $this->language_files_dir, 0777 );
		@mkdir( $this->temp_language_files_dir, 0777 );
		@mkdir( $this->packages_dir, 0777 );
	}

	/**
	 * Start making stuff.
	 */
	public function run() {
		$this->directory_list = $this->list_directory( $this->language_files_dir );
		$this->copy_to_temp_dir( $this->temp_language_files_dir );
		$this->translations = $this->process_directory( $this->directory_list );
		$this->create_mo_files( $this->temp_language_files_dir );
		$this->create_js_files( $this->temp_language_files_dir);
		$this->packages = $this->create_packages();
		$this->create_language_packs();
		$this->create_json();
	}

	/**
	 * Create an array of the directory contents.
	 *
	 * @param string $dir filepath
	 *
	 * @return array $dir_list Listing of directory contents.
	 */
	private function list_directory( $dir ) {
		$dir_list = array();

		// Only add mo/po/zip/json files.
		foreach ( glob( $dir . '/*.{mo,po,zip,json}', GLOB_BRACE ) as $file ) {
			$dir_list[] = basename( $file );
		}

		return $dir_list;
	}

	/**
	 * Copy files from language files directory to temp directory for processing.
	 *
	 * @return void
	 */
	private function copy_to_temp_dir() {
		foreach ( $this->directory_list as $file ) {
			copy( "$this->language_files_dir/$file", "$this->temp_language_files_dir/$file" );
		}
		$this->directory_list = $this->list_directory( $this->temp_language_files_dir );
	}

	/**
	 * Returns a string of the translation name.
	 *
	 * @param string $filename Filename.
	 *
	 * @return string $dir_list Listing of directory contents.
	 */
	private function process_name( $filename ) {
		if ( 'json' === pathinfo( $filename, PATHINFO_EXTENSION ) ) {

			// Parse filename.
			$list = explode( '-', pathinfo( $filename, PATHINFO_FILENAME ) );

			// Remove the md5 part.
			array_pop( $list );

			return implode( '-', $list );
		}
		return pathinfo( $filename, PATHINFO_FILENAME );
	}

	/**
	 * Returns an array of translations with stripped file extension.
	 *
	 * @param array $dir_list Listing of directory contents.
	 *
	 * @return array $translation_list An array of translations.
	 */
	private function process_directory( $dir_list ) {
		$translation_list = array_map(
			array( $this, 'process_name' ),
			$dir_list
		);
		$translation_list = array_unique( $translation_list );

		return $translation_list;
	}

	/**
	 * Create .mo files from .po files.
	 *
	 * @param string $dir File path to temporary language files directory.
	 *
	 * @return void
	 */
	private function create_mo_files( $dir ) {
		foreach ( glob( "$dir/*.po" ) as $file ) {
			$base             = str_replace( '.po', '', basename( $file ) );
			$po_list[ $base ] = $file;
		}

		foreach ( $this->translations as $locale ) {
			$translations = Translations::fromPoFile( $po_list[ $locale ] );
			$translations->toMoFile( "$dir/$locale.mo" );
		}

		// Re-create directory list of files to include new .mo files.
		$this->directory_list = $this->list_directory( $dir );
	}

	private function create_js_files( $dir){
		new Loader::init($this->root_dir . '/vendor');
		$class = new MakeJsonCommand();
		$reflection = new \ReflectionClass('\WP_CLI\I18n\MakeJsonCommand');
		$make_json = $reflection->getMethod('make_json');
		$make_json->setAccessible(true);

		foreach ( glob( "$dir/*.po" ) as $file ) {
			$base             = str_replace( '.po', '', basename( $file ) );
			$po_list[ $base ] = $file;
		}

		foreach( $this->translations as $locale ){
			$params = array("$this->temp_language_files_dir/$locale.po", $this->temp_language_files_dir);
			$make_json->invokeArgs($class, $params);
		}

		// Re-create directory list of files to include new .json files.
		$this->directory_list = $this->list_directory( $dir );
	}

	/**
	 * Creates an associative array of translations from directory listing.
	 *
	 * @return array $packages Associative array of translation files per translation.
	 */
	private function create_packages() {
		$packages = array();
		foreach ( $this->translations as $translation ) {
			$package = array();
			foreach ( $this->directory_list as $file ) {
				if ( false !== stripos( $file, $translation ) ) {
					$package[] = $this->temp_language_files_dir . '/' . $file;
				}
			}
			$packages[ $translation ] = $package;
		}

		return $packages;
	}

	/**
	 * Create language pack zipfiles.
	 */
	private function create_language_packs() {
		foreach ( $this->packages as $translation => $files ) {
			$this->create_zip( $files, $this->packages_dir . '/' . $translation . '.zip', true );
		}
	}

	/**
	 * Create individual zipfile.
	 *
	 * @link https://davidwalsh.name/create-zip-php
	 *
	 * @param array  $files       Array of .mo/.po files for each translation.
	 * @param string $destination Filepath to zipfile.
	 * @param bool   $overwrite   Boolean to set zipfile creation overwrite mode.
	 *
	 * @return bool
	 */
	private function create_zip( $files = array(), $destination = '', $overwrite = true ) {
		// if the zip file already exists and overwrite is false, return false.
		if ( file_exists( $destination ) && ! $overwrite ) {
			return false;
		}

		// create the archive.
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $destination, \ZIPARCHIVE::OVERWRITE | \ZIPARCHIVE::CREATE ) ) {
			return false;
		}
		// add the files.
		foreach ( $files as $file ) {
			$zip->addFile( $file, basename( $file ) );
		}

		// close the zip -- done!
		$zip->close();

		// check to make sure the file exists.
		if ( file_exists( $destination ) ) {
			printf( "\n" . basename( $destination ) . ' created. <br>' );
		} else {
			printf( "\n<span style='color:#f00'>" . basename( $destination ) . ' failed.</span><br>' );
		}
	}

	/**
	 * Create JSON file of translations for export and use by GitHub Updater.
	 */
	private function create_json() {
		$packages = $this->list_directory( $this->packages_dir );
		$arr      = array();

		foreach ( $packages as $package ) {
			foreach ( $this->translations as $translation ) {
				if ( false !== stripos( $package, $translation ) ) {
					$locale                       = ltrim( strrchr( $translation, '-' ), '-' );
					$arr[ $locale ]['slug']       = stristr( $translation, strrchr( $translation, '-' ), true );
					$arr[ $locale ]['language']   = $locale;
					$arr[ $locale ]['updated']    = $this->get_po_revision( "$translation.po" );
					$arr[ $locale ]['package']    = '/packages/' . $package;
					$arr[ $locale ]['autoupdate'] = '1';
				}
			}
		}

		file_put_contents( $this->root_dir . '/language-pack.json', json_encode( $arr ) );
		printf( "\n<br>" . 'language-pack.json created.' . "\n" );
	}

	/**
	 * Returns PO-Revision-Date from .po file.
	 *
	 * @param $file File name.
	 *
	 * @return string
	 */
	private function get_po_revision( $file ) {
		$file         = $this->temp_language_files_dir . '/' . $file;
		$translations = Translations::fromPoFile( $file );

		return $translations->getHeader( 'PO-Revision-Date' );
	}
}
