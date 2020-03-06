<?php

namespace ImageProxy\Classes;


use SplFileInfo;

class Reformer {

	private $proxy;
	private $siteUrl = false;

	public function __construct() {

		if ( ! is_admin() || wp_doing_ajax() ) {

			if ( ! is_blog_admin() ) {

				$this->proxy = new Builder();

				add_filter( 'wp_get_attachment_image_src', [ $this, 'src' ], 20, 3 );

				add_filter( 'wp_calculate_image_srcset', [ $this, 'srcset' ], 20, 5 );

				add_filter( 'the_content', [ $this, 'postHtml' ], 20 );

				add_filter( 'wp_get_attachment_metadata', [ $this, 'generateVirtualSizes' ], 20, 2 );
			}

		}

		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'disableGenerateThumbnails' ], 20, 1 );

	}

	private function replaceHost( $url ) {

		if ( empty( $this->siteUrl ) ) {
			return $url;
		}

		$host   = parse_url( $url, PHP_URL_HOST );
		$scheme = parse_url( $url, PHP_URL_SCHEME );

		$newHost   = parse_url( $this->siteUrl, PHP_URL_HOST );
		$newScheme = parse_url( $this->siteUrl, PHP_URL_SCHEME );

		return str_replace( "{$scheme}://{$host}", "{$newScheme}://{$newHost}", $url );
	}

	public function disableGenerateThumbnails( $sizes ) {
		$this->cliHelper();

		$sizes = $this->getDefaultImageSize( false );

		return $sizes;
	}

	/**
	 * Remove intermediate image sizes inside WP CLI
	 */
	private function cliHelper() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			remove_image_size( '1536x1536' );
			remove_image_size( '2048x2048' );
		}
	}

	public function generateVirtualSizes( $data, $id ) {

		$sizes    = wp_get_additional_image_sizes();
		$sizes    = array_merge( $sizes, $this->getDefaultImageSize() );
		$baseName = basename( $data['file'] );

		$virtualSizesGenerate = function ( $elem ) use ( $baseName, $id ) {
			$c      = 100;
			$width  = $elem['width'];
			$height = $elem['height'];
			$out    = [];

			if ( $c <= $width && $c <= $height ) {

				$p = $width / $height;
				$i = $width;

				for ( ; $i > $c; $i = $i - $c ) {

					if ( $c < $i ) {

						$w = $i;
						$h = (int) round( $w / $p );

						$out["image_{$w}x{$h}"] = [
							'file'      => $this->addStrSize( $baseName, "-{$w}x{$h}" ),
							'width'     => $w,
							'height'    => $h,
							'mime-type' => get_post_mime_type( $id )
						];
					}
				}

				if ( ! empty( $out ) ) {
					return $out;
				}
			}

			return false;
		};

		$newSizes = [];

		foreach ( $sizes as $key => $val ) {
			$p = $data['width'] / $data['height'];

			unset( $val['crop'] );

			$newSizes[ $key ] = array_merge(
				[ 'file' => $baseName ],
				$val,
				[ 'mime-type' => get_post_mime_type( $id ) ]
			);

			if ( empty( $val['height'] ) ) {

				if ( ! empty( $newSizes[ $key ]['width'] ) ) {
					$newSizes[ $key ]['height'] = (int) round( $data['width'] / $p );
				}
			}

			$newSizes[ $key ]['file'] = $this->addStrSize(
				$baseName,
				"-{$newSizes[ $key ]['width']}x{$newSizes[ $key ]['height']}"
			);

		}

		foreach ( $newSizes as $key => $val ) {

			$adinational[ $key ] = $val;

			$tmp = $virtualSizesGenerate( $val );

			if ( false !== $tmp ) {
				$adinational = array_merge( $adinational, $tmp );
			}

		}

		$data['sizes'] = $adinational;

		if ( 106013 == $id ) {
			d( $data );
		}

		return $data;

	}

	/**
	 * @return array
	 *
	 * Получаем размеры стандарных миниатюр
	 */
	private function getDefaultImageSize( $touch = true ) {
		$defaultSizes = [ 'thumbnail', 'medium', 'medium_large', 'large' ];

		$out = [];
		foreach ( $defaultSizes as $defaultSize ) {

			$width  = (int) get_option( "{$defaultSize}_size_w", 0 );
			$height = (int) get_option( "{$defaultSize}_size_h", 0 );

			if ( ! empty( $width ) && ! empty( $height ) ) {
				$out[ $defaultSize ] = [
					'width'  => $width,
					'height' => $height,
					'crop'   => get_option( "{$defaultSize}_crop", false ),
				];
			}
		}

		if ( $touch ) {
			$out["image_512x512"] = [
				'width'  => 512,
				'height' => 512,
				'crop'   => true,
			];

			$out["image_270x270"] = [
				'width'  => 270,
				'height' => 270,
				'crop'   => true,
			];

			$out["image_192x192"] = [
				'width'  => 192,
				'height' => 192,
				'crop'   => true,
			];

			$out["image_180x180"] = [
				'width'  => 180,
				'height' => 180,
				'crop'   => true,
			];

			$out["image_152x152"] = [
				'width'  => 152,
				'height' => 152,
				'crop'   => true,
			];

			$out["image_120x120"] = [
				'width'  => 120,
				'height' => 120,
				'crop'   => true,
			];

			$out["image_76x76"] = [
				'width'  => 76,
				'height' => 76,
				'crop'   => true,
			];

			$out["image_32x32"] = [
				'width'  => 32,
				'height' => 32,
				'crop'   => true,
			];
		}

		return $out;
	}

	public function srcset( $sources, $sizeArray, $imageSrc, $imageMeta, $id ) {

		$sizes = $imageMeta['sizes'];

		$sizesByName = function ( $name ) use ( $sizes ) {
			$name = basename( $name );

			foreach ( $sizes as $size ) {

				if ( $name == $size['file'] ) {
					return $size;
				}
			}

			return false;
		};

		$dirUpload  = wp_get_upload_dir();
		$originFile = $dirUpload['baseurl'] . "/" . $imageMeta['file'];

		$originFile = $this->replaceHost( $originFile );

		$out = [];

		foreach ( $sources as $source ) {
			$findSize = $sizesByName( $source['url'] );

			if ( empty( $findSize ) ) {
				$source['url'] = $imageSrc;
			} else {

				$source['url'] = $this->proxy->builder(
					[
						'width'  => empty( $findSize['width'] ) ? 0 : $findSize['width'],
						'height' => empty( $findSize['height'] ) ? 0 : $findSize['height'],
					],
					$originFile
				);
			}

			$out[] = $source;
		}

		return $out;
	}

	private function checkDomainReplace( $url ) {
		$pattern = "/^.*" . str_replace(
				[ '/', 'http', 'https', 'www.' ],
				[ '\\/', '', '', '' ],
				site_url( '' )
			) . '/iU';

		preg_match( $pattern, $url, $matches );

		if ( ! empty( $this->siteUrl ) ) {
			return true;
		}

		if ( empty( $matches ) ) {
			return false;
		}


		return true;
	}

	public function postHtml( $html ) {

		return $this->regexSrc( $html );
	}

	public function src( $image, $id, $size ) {

		$sizes = wp_get_additional_image_sizes();
		$sizes = array_merge( $sizes, $this->getDefaultImageSize() );

		$s = "?origin=" . _wp_get_attachment_relative_path( $image[0] . "/" . basename( $image[0] ) );

		$image[0] = $this->replaceHost( $image['0'] );

		if ( isset( $image[0] ) ) {

			if ( is_string( $size ) ) {
				$sizeMeta = ( isset( $sizes[ $size ] ) ? $sizes[ $size ] : 0 );

				$image[0] = $this->proxy->builder(
					[
						'width'  => empty( $sizeMeta['width'] ) ? 0 : $sizeMeta['width'],
						'height' => empty( $sizeMeta['height'] ) ? 0 : $sizeMeta['height'],
					],
					$image[0]
				);
			} elseif ( is_array( $size ) ) {
				$url = wp_get_attachment_url( $id );


				$url = $this->replaceHost( $url );

				$image[0] = $this->proxy->builder(
					[
						'width'  => ! isset( $size[0] ) ? 0 : $size[0],
						'height' => ! isset( $size[1] ) ? 0 : $size[1],
					],
					$url
				);
			}

		}

		$image[0] = $image[0] . $s;

		return $image;
	}

	public function regexSrc( $str ) {
		preg_match_all( '~<img.*>~im', $str, $images );

		$array = [];

		if ( isset( $images[0] ) && ! empty( $images[0] ) ) {

			foreach ( $images[0] as $image ) {

				$src = $this->getAttribute( 'src', $image );


				if ( $this->checkDomainReplace( $src ) ) {

					$height = $this->getAttribute( 'height', $image );
					$width  = $this->getAttribute( 'width', $image );

					$imageSrc = $src;

					$imageSrc = $this->replaceHost( $imageSrc );

					$array[ $src ] = $this->proxy->builder(
						[
							'width'  => $width,
							'height' => $height
						],
						$imageSrc
					);

				}

			}

			if ( ! empty( $array ) ) {
				return str_replace( array_keys( $array ), array_values( $array ), $str );
			}
		}

		return $str;
	}

	/**
	 * Get html attribute by name
	 *
	 * @param $str
	 * @param $atr
	 *
	 * @return mixed
	 */
	public function getAttribute( $atr, $str ) {
		preg_match( "~{$atr}=[\"|'](.*)[\"|']\s~imU", $str, $m );

		if ( isset( $m[1] ) ) {
			return $m[1];
		}

		return '';
	}

	private function getFileExtension( $string ) {
		$info = new SplFileInfo( $string );

		return $info->getExtension();
	}

	private function addStrSize( $fileString, $fileSizeString ) {

		$extension = $this->getFileExtension( $fileString );

		$pattern = "(\.{$extension}$)";

		return preg_replace(
			"/$pattern/i",
			"{$fileSizeString}$1",
			$fileString
		);
	}

}
