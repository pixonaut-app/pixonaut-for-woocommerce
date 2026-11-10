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
 * A product's groups carried to a variation on another template.
 */
final class CarriedFormGroupsTest extends TestCase {

	/** The product's own group, holding both attributes. */
	private const OWN = array(
		'id'    => 'wc-personalise',
		'title' => 'Personalisering & Storlek',
		'items' => array(
			array( 'attribute' => 'posterstorlek' ),
			array( 'attribute' => 'personalisering' ),
		),
	);

	/** The product's arrangement: its own group, then its template's two, left untitled. */
	private const ARRANGEMENT = array(
		'groups' => array(
			self::OWN,
			array(
				'id'    => 'photo',
				'items' => array(
					array(
						'field' => 'picture:imageAssetId',
						'label' => 'Your photo',
					),
				),
			),
			array(
				'id'    => 'names',
				'items' => array(
					array( 'field' => 'name:content' ),
					array( 'field' => 'date:content' ),
				),
			),
			array(
				'id'    => 'dates',
				'title' => 'When',
				'items' => array( array( 'field' => 'date:content' ) ),
			),
		),
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '_x' )->returnArg();
	}

	public function test_a_variation_on_another_template_keeps_the_products_groups_and_names(): void {
		$carried = FormGroupsMount::carried( self::ARRANGEMENT, self::product_template(), self::other_template() );
		$fitted  = FormGroupsMount::fitted( $carried, array_column( TemplateForm::groups( self::other_template() ), 'id' ) );

		$this->assertSame(
			array(
				'groups' => array(
					self::OWN,
					array(
						'id'    => 'photo',
						'items' => array(
							array(
								'field' => 'image:imageAssetId',
								'label' => 'Your photo',
							),
						),
						'title' => 'Foto',
					),
					array(
						'id'    => 'names',
						'items' => array( array( 'field' => 'title:content' ) ),
						'title' => 'Namn och uppgifter',
					),
				),
			),
			$fitted,
			'a field with no key, or none the other template has, is left out, and the group it alone held'
		);
	}

	public function test_a_group_the_other_template_has_stays_however_little_it_keeps(): void {
		$carried = FormGroupsMount::carried(
			array(
				'groups' => array(
					array(
						'id'    => 'text',
						'items' => array( array( 'field' => 'date:content' ) ),
					),
				),
			),
			self::product_template(),
			self::other_template()
		);

		$this->assertSame(
			array(
				'groups' => array(
					array(
						'id'    => 'text',
						'items' => array(),
					),
				),
			),
			$carried,
			'the product’s template has no such group to name it after'
		);
	}

	public function test_an_arrangement_made_for_the_other_template_keeps_its_fields(): void {
		$own = array(
			'groups' => array(
				array(
					'id'    => 'text',
					'title' => 'Your words',
					'items' => array(
						array(
							'field' => 'extra:content',
							'label' => 'A line of your own',
						),
					),
				),
			),
		);

		$this->assertSame( $own, FormGroupsMount::carried( $own, self::product_template(), self::other_template() ) );
	}

	public function test_only_an_arrangement_naming_more_than_the_options_own_groups_needs_the_template_read(): void {
		$options_first = array(
			'groups' => array(
				array(
					'id'    => FormGroupsMount::OPTION_GROUP_PREFIX . 'size',
					'title' => 'Size',
					'items' => array( array( 'attribute' => 'size' ) ),
				),
			),
		);

		$this->assertFalse( FormGroupsMount::needs_template( $options_first ) );
		$this->assertFalse( FormGroupsMount::needs_template( null ) );
		$this->assertTrue( FormGroupsMount::needs_template( self::ARRANGEMENT ) );
	}

	public function test_without_a_read_of_the_products_template_only_attributes_and_titles_carry(): void {
		$carried = FormGroupsMount::carried( self::ARRANGEMENT, null, self::other_template() );

		$this->assertSame( array( self::OWN ), $carried['groups'] ?? null );
		$this->assertNull( FormGroupsMount::carried( null, null, self::other_template() ) );
	}

	public function test_field_keys_are_read_as_pixonaut_reads_them(): void {
		$keys = TemplateForm::field_keys(
			array(
				'template' => array(
					'background' => array(
						'color' => array(
							'editable' => true,
							'fieldKey' => 'colour',
						),
					),
					'shape'      => array(
						'editable' => true,
						'fieldKey' => 'shape',
					),
					'layers'     => array(
						array(
							'id'      => 'name',
							'props'   => array(
								'content'    => array(
									'editable' => true,
									'fieldKey' => 'name',
								),
								'fontFamily' => array(
									'editable' => false,
									'fieldKey' => 'font',
								),
							),
							'opacity' => array(
								'editable' => true,
								'fieldKey' => 'fade',
							),
						),
						array(
							'id'    => 'second',
							'props' => array(
								'content' => array(
									'editable' => true,
									'fieldKey' => 'name',
								),
							),
						),
						array( 'props' => array() ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'colour' => '$artwork:color',
				'shape'  => '$artwork:shape',
				'name'   => 'name:content',
				'fade'   => 'name:opacity',
			),
			$keys,
			'only offered fields, and of two sharing a key the first'
		);
		$this->assertSame( array(), TemplateForm::field_keys( 'not a template' ) );
	}

	/**
	 * The product's template: a photo and a names group, the photo and the
	 * name keyed, the date not.
	 *
	 * @return array<string, mixed>
	 */
	private static function product_template(): array {
		return self::template(
			array(
				'photo' => array( 'Foto', array( 'picture:imageAssetId' ) ),
				'names' => array( 'Namn och uppgifter', array( 'name:content', 'date:content' ) ),
			),
			array(
				'photo' => 'picture:imageAssetId',
				'name'  => 'name:content',
			)
		);
	}

	/**
	 * The variation's template: the photo and the name on other layers, in
	 * other groups, and a keyed field of its own.
	 *
	 * @return array<string, mixed>
	 */
	private static function other_template(): array {
		return self::template(
			array(
				'pic'  => array( 'Bild', array( 'image:imageAssetId' ) ),
				'text' => array( 'Text', array( 'title:content', 'extra:content' ) ),
			),
			array(
				'photo' => 'image:imageAssetId',
				'name'  => 'title:content',
				'other' => 'extra:content',
			)
		);
	}

	/**
	 * A template as Pixonaut answers it.
	 *
	 * @param array<string, array{0: string, 1: list<string>}> $steps Each group's label and fields, by group id.
	 * @param array<string, string>                            $keys  Fields by field key.
	 * @return array<string, mixed>
	 */
	private static function template( array $steps, array $keys ): array {
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
		$layers = array();
		foreach ( $keys as $key => $field ) {
			list( $layer, $prop ) = explode( ':', $field );
			$layers[]             = array(
				'id'    => $layer,
				'props' => array(
					$prop => array(
						'editable' => true,
						'fieldKey' => $key,
					),
				),
			);
		}
		return array(
			'template'   => array( 'layers' => $layers ),
			'formSchema' => array( 'steps' => $schema ),
		);
	}
}
