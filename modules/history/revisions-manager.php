<?php
namespace Elementor\Modules\History;

use Elementor\Plugin;
use Elementor\Post_CSS_File;
use Elementor\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Revisions_Manager {

	const MAX_REVISIONS_TO_DISPLAY = 100;

	private static $authors = [];

	public function __construct() {
		self::register_actions();
	}

	public static function handle_revision() {
		add_filter( 'wp_save_post_revision_post_has_changed', '__return_true' );
		add_action( '_wp_put_post_revision', [ __CLASS__, 'save_revision' ] );
	}

	public static function get_revisions( $post_id = 0, $query_args = [], $parse_result = true ) {
		$post = get_post( $post_id );

		if ( ! $post || empty( $post->ID ) ) {
			return [];
		}

		$revisions = [];

		$default_query_args = [
			'posts_per_page' => self::MAX_REVISIONS_TO_DISPLAY,
			'meta_key' => '_elementor_data',
		];

		$query_args = array_merge( $default_query_args, $query_args );

		$posts = wp_get_post_revisions( $post->ID, $query_args );

		if ( ! wp_revisions_enabled( $post ) ) {
			$autosave = wp_get_post_autosave( $post->ID );
			if ( $autosave ) {
				if ( $parse_result ) {
					array_unshift( $posts, $autosave );
				} else {
					array_unshift( $posts, $autosave->ID );
				}
			}
		}

		if ( $parse_result ) {
			array_unshift( $posts, $post );
		} else {
			array_unshift( $posts, $post->ID );
			return $posts;
		}

		$current_time = current_time( 'timestamp' );

		/** @var \WP_Post $revision */
		foreach ( $posts as $revision ) {
			$date = date_i18n( _x( 'M j @ H:i', 'revision date format', 'elementor' ), strtotime( $revision->post_modified ) );

			$human_time = human_time_diff( strtotime( $revision->post_modified ), $current_time );

			if ( $revision->ID === $post->ID ) {
				$type = 'current';
			} elseif ( false !== strpos( $revision->post_name, 'autosave' ) ) {
				$type = 'autosave';
			} else {
				$type = 'revision';
			}

			if ( ! isset( self::$authors[ $revision->post_author ] ) ) {
				self::$authors[ $revision->post_author ] = [
					'avatar' => get_avatar( $revision->post_author, 22 ),
					'display_name' => get_the_author_meta( 'display_name' , $revision->post_author ),
				];
			}

			$revisions[] = [
				'id' => $revision->ID,
				'author' => self::$authors[ $revision->post_author ]['display_name'],
				'timestamp' => strtotime( $revision->post_modified ),
				'date' => sprintf(
					/* translators: 1: Human readable time difference, 2: Date */
					__( '%1$s ago (%2$s)', 'elementor' ),
					$human_time,
					$date
				),
				'type' => $type,
				'gravatar' => self::$authors[ $revision->post_author ]['avatar'],
			];
		}

		return $revisions;
	}

	public static function save_revision( $revision_id ) {
		$parent_id = wp_is_post_revision( $revision_id );

		if ( ! $parent_id || ! Plugin::$instance->db->is_built_with_elementor( $parent_id ) ) {
			return;
		}

		Plugin::$instance->db->copy_elementor_meta( $parent_id, $revision_id );
	}

	public static function restore_revision( $parent_id, $revision_id ) {
		$is_built_with_elementor = Plugin::$instance->db->is_built_with_elementor( $revision_id );

		Plugin::$instance->db->set_is_elementor_page( $parent_id, $is_built_with_elementor );

		if ( ! $is_built_with_elementor ) {
			return;
		}

		Plugin::$instance->db->copy_elementor_meta( $revision_id, $parent_id );

		$post_css = new Post_CSS_File( $parent_id );

		$post_css->update();
	}

	public static function on_revision_data_request() {
		Plugin::$instance->editor->verify_ajax_nonce();

		if ( ! isset( $_POST['id'] ) ) {
			wp_send_json_error( 'You must set the revision ID' );
		}

		$revision = get_post( $_POST['id'] );

		if ( empty( $revision ) ) {
			wp_send_json_error( 'Invalid Revision' );
		}

		if ( ! current_user_can( 'edit_post', $revision->ID ) ) {
			wp_send_json_error( __( 'Access Denied.', 'elementor' ) );
		}

		$revision_data = Plugin::$instance->db->get_plain_editor( $revision->ID );

		wp_send_json_success( $revision_data );
	}

	public static function on_delete_revision_request() {
		Plugin::$instance->editor->verify_ajax_nonce();

		if ( empty( $_POST['id'] ) ) {
			wp_send_json_error( 'You must set the id' );
		}

		$revision = get_post( $_POST['id'] );

		if ( empty( $revision ) ) {
			wp_send_json_error( 'Invalid Revision' );
		}

		if ( ! current_user_can( 'delete_post', $revision->ID ) ) {
			wp_send_json_error( __( 'Access Denied.', 'elementor' ) );
		}

		$deleted = wp_delete_post_revision( $revision->ID );

		if ( $deleted && ! is_wp_error( $deleted ) ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( __( 'Cannot delete this Revision', 'elementor' ) );
		}
	}

	public static function add_revision_support_for_all_post_types() {
		$post_types = get_post_types_by_support( 'elementor' );
		foreach ( $post_types as $post_type ) {
			add_post_type_support( $post_type, 'revisions' );
		}
	}

	public static function on_ajax_save_builder_data( $return_data, $post_id ) {
		$post_id = $_POST['post_id'];

		$latest_revisions = self::get_revisions(
			$post_id, [
				'posts_per_page' => 1,
			]
		);

		$all_revision_ids = self::get_revisions(
			$post_id, [
				'fields' => 'ids',
			], false
		);

		// Send revisions data only if has revisions.
		if ( ! empty( $latest_revisions ) ) {
			$current_revision_id = self::current_revision_id( $post_id );

			$return_data = array_replace_recursive( $return_data, [
				'config' => [
					'current_revision_id' => $current_revision_id,
				],
				'latest_revisions' => $latest_revisions,
				'revisions_ids' => $all_revision_ids,
			] );
		}

		return $return_data;
	}

	public static function db_before_save( $status, $has_changes ) {
		if ( $has_changes ) {
			self::handle_revision();
		}
	}

	public static function editor_settings( $settings, $post_id ) {
		$settings = array_replace_recursive( $settings, [
			'revisions' => self::get_revisions(),
			'revisions_enabled' => ( $post_id && wp_revisions_enabled( get_post( $post_id ) ) ),
			'current_revision_id' => self::current_revision_id( $post_id ),
			'i18n' => [
				'edit_draft' => __( 'Edit Draft', 'elementor' ),
				'edit_published' => __( 'Edit Published', 'elementor' ),
				'no_revisions_1' => __( 'Revision history lets you save your previous versions of your work, and restore them any time.', 'elementor' ),
				'no_revisions_2' => __( 'Start designing your page and you\'ll be able to see the entire revision history here.', 'elementor' ),
				'current' => __( 'Current Version', 'elementor' ),
				'restore' => __( 'Restore', 'elementor' ),
				'restore_auto_saved_data' => __( 'Restore Auto Saved Data', 'elementor' ),
				'restore_auto_saved_data_message' => __( 'There is an autosave of this post that is more recent than the version below. You can restore the saved data fron the Revisions panel', 'elementor' ),
				'revision' => __( 'Revision', 'elementor' ),
				'revision_history' => __( 'Revision History', 'elementor' ),
				'revisions_disabled_1' => __( 'It looks like the post revision feature is unavailable in your website.', 'elementor' ),
				'revisions_disabled_2' => sprintf(
					/* translators: %s: Codex URL */
					__( 'Learn more about <a targe="_blank" href="%s">WordPress revisions</a>', 'elementor' ),
					'https://codex.wordpress.org/Revisions#Revision_Options'
				),
			],
		] );

		return $settings;
	}

	private static function register_actions() {
		add_action( 'wp_restore_post_revision', [ __CLASS__, 'restore_revision' ], 10, 2 );
		add_action( 'init', [ __CLASS__, 'add_revision_support_for_all_post_types' ], 9999 );
		add_filter( 'elementor/editor/localize_settings', [ __CLASS__, 'editor_settings' ], 10, 2 );
		add_action( 'elementor/db/before_save', [ __CLASS__, 'db_before_save' ], 10, 2 );

		if ( Utils::is_ajax() ) {
			add_filter( 'elementor/ajax_save_builder/return_data', [ __CLASS__, 'on_ajax_save_builder_data' ], 10, 2 );
			add_action( 'wp_ajax_elementor_get_revision_data', [ __CLASS__, 'on_revision_data_request' ] );
			add_action( 'wp_ajax_elementor_delete_revision', [ __CLASS__, 'on_delete_revision_request' ] );
		}
	}

	private static function current_revision_id( $post_id ) {
		$current_revision_id = $post_id;
		$autosave = wp_get_post_autosave( $post_id );

		if ( is_object( $autosave ) ) {
			$current_revision_id = $autosave->ID;
		}

		return $current_revision_id;
	}
}
