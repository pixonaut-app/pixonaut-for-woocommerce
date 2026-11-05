<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\TemplateForm;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class FormGroupsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $text ): string => trim( (string) preg_replace( '/<[^>]*>/', '', $text ) ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $text ): string => (string) preg_replace( '/<[^>]*>/', '', $text ) );
		Functions\when( 'sanitize_title' )->alias( static fn ( string $title ): string => strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $title ) ) );
	}

	public function test_reads_groups_fields_and_attributes_in_order(): void {
		$groups = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'text-front',
						'title' => ' 1. Your shirt ',
						'items' => array(
							array( 'attribute' => 'pa_size' ),
							array(
								'field'       => 'slogan:content',
								'label'       => 'Name on the back',
								'help'        => '',
								'placeholder' => '<b>Your name</b>',
								'options'     => array(
									'Anton' => 'Bold',
									'0'     => 'Zero',
									'empty' => '',
								),
							),
						),
					),
					array(
						'id'    => 'photo',
						'items' => array(),
					),
				),
			)
		);

		$this->assertNotNull( $groups );
		$this->assertSame(
			array(
				array(
					'id'    => 'text-front',
					'title' => '1. Your shirt',
					'items' => array(
						array( 'attribute' => 'pa_size' ),
						array(
							'field'       => 'slogan:content',
							'label'       => 'Name on the back',
							'placeholder' => 'Your name',
							'options'     => array(
								'Anton' => 'Bold',
								'0'     => 'Zero',
							),
						),
					),
				),
				array(
					'id'    => 'photo',
					'items' => array(),
				),
			),
			$groups->groups
		);
		$this->assertSame( array( 'pa_size' ), $groups->attributes() );
	}

	public function test_offered_values_stay_an_object_when_stored(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$groups = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'size',
						'items' => array(
							array(
								'field'   => 'size:value',
								'options' => array( 'Small', 'Large' ),
							),
						),
					),
				),
			)
		);

		$this->assertNotNull( $groups );
		$this->assertSame( '{"groups":[{"id":"size","items":[{"field":"size:value","options":{"0":"Small","1":"Large"}}]}]}', wp_json_encode( $groups->to_array() ) );
		$this->assertEquals( $groups, FormGroups::from_json( (string) wp_json_encode( $groups->to_array() ) ) );
	}

	public function test_leaves_out_what_the_designer_would_refuse(): void {
		$groups = FormGroups::from_array(
			array(
				'groups' => array(
					array( 'items' => array() ),
					'text',
					array(
						'id'    => 'text-front',
						'items' => array( array( 'field' => 'a:b' ), array( 'field' => 'a:b' ), array( 'label' => 'no field' ), 'x' ),
					),
					array(
						'id'    => 'text-front',
						'items' => array(),
					),
					// A group of the product's own needs a name.
					array(
						'id'    => FormGroups::OWN_GROUP_PREFIX . 'gift',
						'items' => array( array( 'attribute' => 'gift' ) ),
					),
				),
			)
		);

		$this->assertNotNull( $groups );
		$this->assertSame(
			array(
				array(
					'id'    => 'text-front',
					'items' => array( array( 'field' => 'a:b' ) ),
				),
			),
			$groups->groups
		);
	}

	public function test_nothing_arranged_is_null(): void {
		$this->assertNull( FormGroups::from_array( null ) );
		$this->assertNull( FormGroups::from_array( array( 'groups' => array() ) ) );
		$this->assertNull( FormGroups::from_json( '' ) );
		$this->assertNull( FormGroups::from_json( 'not json' ) );
	}

	public function test_reads_a_templates_groups_and_fields(): void {
		$groups = TemplateForm::groups(
			array(
				'formSchema' => array(
					'steps' => array(
						array(
							'groupId' => 'text-front',
							'label'   => 'Text',
							'fields'  => array(
								array(
									'layerId'     => 'slogan',
									'prop'        => 'fontFamily',
									'type'        => 'select',
									'label'       => 'Typsnitt',
									'description' => 'Pick one',
									'constraints' => array( 'oneOf' => array( 'Anton', 'Poppins', array( 'not' => 'a value' ) ) ),
								),
								array( 'layerId' => 'broken' ),
							),
						),
						array( 'label' => 'No group' ),
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'     => 'text-front',
					'label'  => 'Text',
					'fields' => array(
						array(
							'field'   => 'slogan:fontFamily',
							'label'   => 'Typsnitt',
							'help'    => 'Pick one',
							'type'    => 'select',
							'options' => array( 'Anton', 'Poppins' ),
						),
					),
				),
			),
			$groups
		);
		$this->assertSame( array(), TemplateForm::groups( 'not a template' ) );
	}
}
