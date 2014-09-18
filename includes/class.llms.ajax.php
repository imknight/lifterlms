<?php
/**
 * lifterLMS AJAX Event Handler
 *
 * @author 		codeBOX
 * @category 	Core
 * @version     0.1
 */

if ( ! defined( 'ABSPATH' ) ) exit; 


class LLMS_AJAX {

	/**
	 * Hook into ajax events
	 */
	public function __construct() {
		$ajax_events = array(
			'get_sections' 		=> false,
			'get_lessons' 		=> false,
			'update_syllabus' 	=> false
		);

		foreach ( $ajax_events as $ajax_event => $nopriv ) {
			add_action( 'wp_ajax_' . $ajax_event, array( $this, $ajax_event ) );

			if ( $nopriv ) {
				add_action( 'wp_ajax_nopriv_' . $ajax_event, array( $this, $ajax_event ) );
			}
		}
	}

	/**
	 * Return array of sections (id => name)
	 *
	 * @param string
	 * @return array
	 */
	public function get_sections(){

		$args = array(
			'post_type' => 'section'

		 );
		$postslist = get_posts( $args );

		echo json_encode($postslist );

		die();
	}

	/**
	 * Return array of lessons (id => name)
	 *
	 * @param string
	 * @return array
	 */
	public function get_lessons(){

		$args = array(
			'post_type' => 'lesson'
		 );

		$postslist = get_posts( $args );

		echo json_encode($postslist);

		die();
	}

	/**
	 * Updates course syllabus JSON object
	 *
	 * @param object
	 * @return string
	 */
	public function update_syllabus() {

		// Parse section id and create new array for comparison.
		function parse_new_sections ( $new_sections_array ) {
			$array = array();

		    foreach ($new_sections_array as $key => $value ) {
				if (is_array( $value) ) {
					foreach ($value as $keys => $values) {
						if ($keys === 'section_id') {
						array_push($array, $values);
						}
					}
					parse_new_sections( $value );
				}
			}
			return $array;
		}

		// Parse section ids returned from DB and create new array for comparison.
		function parse_current_sections ( $current_sections_array ) {
		    $array = array();
		    
		    foreach($current_sections_array[0] as $key => $value ) {
		    	foreach($value as $keys => $values ) {
		    		if ($keys == 'section_id') {
		    		array_push($array, $values);
		    		}
		    	}
		    }
		    return $array;
		}

		// Compare arrays and determine if there are any duplicates. 
		function array_has_dupes($new_array) {
				return count($new_array) !== count(array_unique($new_array));
		}


		if ( isset($_REQUEST) ) {

			$success = 'no'; //default response to no.
			$new_sections_array = $_REQUEST['sections'];
			$current_sections_array = get_post_meta( $_REQUEST['post_id'], '_sections');

			$new_array = parse_new_sections($new_sections_array);
			$old_array = parse_current_sections($current_sections_array);

			$result_array = array_intersect_assoc($new_array, $old_array);

			$new_array_duplicates = array_count_values($result_array);
			if (array_has_dupes($new_array)) {
				$success = 'no';
			}
			else {
				update_post_meta( $_REQUEST['post_id'], '_sections', ( $_REQUEST['sections'] === '' ) ? '' : $_REQUEST['sections']  );
				$success = 'yes';
			}
		}
		//print_r($_REQUEST);
	echo $success;
	die();
	}

}

 new LLMS_AJAX();