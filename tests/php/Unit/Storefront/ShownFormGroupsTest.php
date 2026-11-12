<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\TemplateForm;
use Pixonaut\WooCommerce\Storefront\FormGroupsMount;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

/**
 * The form a side's arrangement shows on a template, as the designer lays it out.
 */
final class ShownFormGroupsTest extends TestCase {

	/** The product's own group, holding both attributes. */
	private const OWN = array(
		'id'    => 'wc-personalise',
		'title' => 'Personalisering & Storlek',
		'items' => array(
			array( 'attribute' => 'posterstorlek' ),
			array( 'attribute' => 'personalisering' ),
		),
	);

	/** The names group, as the store manager arranged it. */
	private const NAMES = array(
		'id'    => 'names',
		'items' => array(
			array( 'field' => 'name:content' ),
			array(
				'field' => 'date:content',
				'label' => 'Date of birth',
			),
		),
	);

	/** The arrangement made on the template with the photo. */
	private const WITH_PHOTO = array(
		'groups' => array(
			self::OWN,
			array(
				'id'    => 'photo',
				'items' => array( array( 'field' => 'picture:imageAssetId' ) ),
			),
			self::NAMES,
		),
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '_x' )->returnArg();
	}

	public function test_the_arrangement_with_the_photo_shows_as_the_one_fitted_without_it_on_the_template_without_it(): void {
		$without = FormGroupsMount::fitted(
			FormGroupsMount::carried( self::WITH_PHOTO, self::with_photo(), self::without_photo() ),
			array_column( TemplateForm::groups( self::without_photo() ), 'id' )
		);

		$this->assertNotSame( self::WITH_PHOTO, $without );
		$this->assertSame(
			array(
				array( 'wc-personalise', 'Personalisering & Storlek', self::OWN['items'] ),
				array( 'names', 'Namn och uppgifter', self::NAMES['items'] ),
			),
			FormGroupsMount::shown_on( self::WITH_PHOTO, self::without_photo() )
		);
		$this->assertSame( FormGroupsMount::shown_on( self::WITH_PHOTO, self::without_photo() ), FormGroupsMount::shown_on( $without, self::without_photo() ) );
	}

	public function test_words_order_and_titled_groups_of_ones_own_tell_two_arrangements_apart(): void {
		$shown                                     = FormGroupsMount::shown_on( self::WITH_PHOTO, self::without_photo() );
		$renamed                                   = self::WITH_PHOTO;
		$renamed['groups'][2]['items'][1]['label'] = 'Born';
		$titled                                    = self::WITH_PHOTO;
		$titled['groups'][1]['title']              = 'Your photo';
		$reordered                                 = array( 'groups' => array( self::NAMES, self::OWN ) );

		$this->assertNotSame( $shown, FormGroupsMount::shown_on( $renamed, self::without_photo() ) );
		$this->assertNotSame( $shown, FormGroupsMount::shown_on( $reordered, self::without_photo() ) );
		// A titled group the template lacks shows only while it holds an item.
		$this->assertSame( $shown, FormGroupsMount::shown_on( $titled, self::without_photo() ) );
		$titled['groups'][1]['items'][] = array( 'attribute' => 'posterstorlek' );
		$this->assertNotSame( $shown, FormGroupsMount::shown_on( $titled, self::without_photo() ) );
	}

	public function test_a_title_the_same_as_the_templates_label_shows_the_same_and_no_arrangement_shows_none(): void {
		$labelled                       = self::WITH_PHOTO;
		$labelled['groups'][2]['title'] = 'Namn och uppgifter';

		$this->assertSame( FormGroupsMount::shown_on( self::WITH_PHOTO, self::without_photo() ), FormGroupsMount::shown_on( $labelled, self::without_photo() ) );
		$this->assertNull( FormGroupsMount::shown_on( null, self::without_photo() ) );
	}

	/**
	 * The template with a photo group and a names group.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_photo(): array {
		return self::template(
			array(
				'photo' => array( 'Foto', array( 'picture:imageAssetId' ) ),
				'names' => array( 'Namn och uppgifter', array( 'name:content', 'date:content' ) ),
			)
		);
	}

	/**
	 * The same template without its photo, layer ids unchanged.
	 *
	 * @return array<string, mixed>
	 */
	private static function without_photo(): array {
		return self::template(
			array(
				'names' => array( 'Namn och uppgifter', array( 'name:content', 'date:content' ) ),
			)
		);
	}

	/**
	 * A template as Pixonaut answers it, with no field keys.
	 *
	 * @param array<string, array{0: string, 1: list<string>}> $steps Each group's label and fields, by group id.
	 * @return array<string, mixed>
	 */
	private static function template( array $steps ): array {
		$schema = array();
		foreach ( $steps as $id => list( $label, $fields ) ) {
			$schema[] = array(
				'groupId' => $id,
				'label'   => $label,
				'fields'  => array_map(
					static function ( string $field ): array {
						list( $layer, $prop ) = explode( ':', $field );
						return array(
							'layerId' => $layer,
							'prop'    => $prop,
							'type'    => 'text',
							'label'   => $field,
						);
					},
					$fields
				),
			);
		}
		return array(
			'template'   => array( 'layers' => array() ),
			'formSchema' => array( 'steps' => $schema ),
		);
	}
}
