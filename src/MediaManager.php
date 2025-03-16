<?php

namespace WP_CLI\AiCommand;

class MediaManager {

	public static function upload_to_media_library($media_path) {
		// Get WordPress upload directory information
		$upload_dir = wp_upload_dir();

		// Get the file name from the path
		$file_name = basename($media_path);

		// Copy file to the upload directory
		$new_file_path = $upload_dir['path'] . '/' . $file_name;
		copy($media_path, $new_file_path);

		// Prepare attachment data
		$wp_filetype = wp_check_filetype($file_name, null);
		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => sanitize_file_name($file_name),
			'post_content'   => '',
			'post_status'    => 'inherit'
		);

		// Insert the attachment
		$attach_id = wp_insert_attachment($attachment, $new_file_path);

		// Generate attachment metadata
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		$attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
		wp_update_attachment_metadata($attach_id, $attach_data);

		return $attach_id;

	}
}