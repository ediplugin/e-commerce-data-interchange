<?php declare( strict_types=1 );
/**
 * Class ProductImagesParser
 *
 * @package BytePerfect\EDI\Parsers
 */

namespace BytePerfect\EDI\Parsers;

// phpcs:disable Squiz.Commenting.FunctionComment.SpacingAfterParamType

use BytePerfect\EDI\EDI;
use BytePerfect\EDI\Utils;
use Exception;
use WC_Product;

/**
 * Class ProductImagesParser
 *
 * @package BytePerfect\EDI\Parsers
 */
class ProductImagesParser {
	public const IMAGE_MAP_KEY = '_edi_1c_image_map';

	/**
	 * AttributesParser constructor.
	 */
	public function __construct() {
		// Получаю аттрибуты товара.
		add_filter(
			'edi_parse_product_xml_object',
			array( $this, 'parse_xml_object' ),
			10,
			2
		);

		// Добавляю аттрибуты товару.
		add_action(
			'edi_product_before_save',
			array( $this, 'process' ),
			10,
			2
		);
	}

	/**
	 * Parse XML object.
	 *
	 * @param array<string, mixed> $product_data Product data.
	 * @param array $xml_data XML data.
	 *
	 * @return array<string, mixed>
	 */
	public function parse_xml_object( array $product_data, array $xml_data ): array {
		$product_data['images'] = array();

		if ( ! isset( $xml_data['Картинка'] ) ) {
			return $product_data;
		}

		foreach ( $xml_data['Картинка'] as $image ) {
			$image = $image['#'];
			$guid  = md5( $image );

			try {
				$image_id = Utils::get_guid_id_match(
				// @phpstan-ignore-next-line
					apply_filters( 'edi_image_map_key', self::IMAGE_MAP_KEY ),
					$guid
				);

				if ( is_null( $image_id ) ) {
					$image_id = $this->upload_image( $image, $guid );
				}

				if ( $image_id ) {
					$product_data['images'][] = $image_id;
				}
			} catch ( Exception $e ) {
				EDI::log()->error( $e->getMessage() );
			}
		}

		return $product_data;
	}

	/**
	 * Process product data.
	 *
	 * @param WC_Product $product Product.
	 * @param array<string, mixed> $product_data Product data.
	 *
	 * @return void
	 */
	public function process( WC_Product &$product, array &$product_data ): void {
		if ( $product_data['images'] ) {
			$product->set_image_id( (int) $product_data['images'][0] );
			$product->set_gallery_image_ids( array_slice( (array) $product_data['images'], 1 ) );

			$my_image_meta = array(
				'post_title'	=> $product->get_title(),	// Set image Title
				//'post_excerpt'	=> $my_image_title,		// Set image Caption (Excerpt)
				//'post_content'	=> $my_image_title,		// Set image Description (Content)
			);
			$this->update_images_meta($product_data['images'],$my_image_meta);

		}
	}

	/**
	 * Upload image.
	 *
	 * @param string $image Image path.
	 * @param string $guid Image GUID.
	 *
	 * @return int
	 *
	 * @throws Exception Exception.
	 */
	protected function upload_image( string $image, string $guid ): int {
		$src = EDI::filesystem()->normalize_path( $image );

		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			include_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			include_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$attachment_id = media_handle_sideload(
			array(
				'tmp_name' => $src,
				'name'     => basename( $src ),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			throw new Exception(
			/* translators: %s: attribute map value. */
				sprintf( __( 'Error upload image: %s', 'edi' ), $attachment_id->get_error_message() )
			);
		}

		Utils::set_guid_id_match(
		// @phpstan-ignore-next-line
			apply_filters( 'edi_image_map_key', self::IMAGE_MAP_KEY ),
			$guid,
			$attachment_id
		);

		return $attachment_id;
	}

	/**
	 * Update images metadata
	 *
	 * @param array $images 	Array of an images ID's.
	 * @param array $image_meta	Array with the image meta (Title, Caption, Description) to be updated
	 *
 	 * @return void
	 *
	 */
	protected function update_images_meta( array $images, array $image_meta ): void {
		foreach ($images as $post_ID) {
			if ( wp_attachment_is_image( $post_ID ) ) {
				// Create an array with the image meta (Title, Caption, Description) to be updated
				// Note:  comment out the Excerpt/Caption or Content/Description lines if not needed
				$my_image_meta = array(
					'ID'		=> $post_ID,						// Specify the image (ID) to be updated
					'post_title'	=> $image_meta['post_title'],	// Set image Title
					//'post_excerpt'	=> $image_meta['post_title'],	// Set image Caption (Excerpt)
					//'post_content'	=> $image_meta['post_title'],	// Set image Description (Content)
				);
		
				// Set the image Alt-Text
				update_post_meta( $post_ID, '_wp_attachment_image_alt', $image_meta['post_title']);
		
				// Set the image meta (e.g. Title, Excerpt, Content)
				wp_update_post( $my_image_meta );
			} 
		}
	}
}
