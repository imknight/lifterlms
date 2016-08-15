<?php
/**
 * Template for the Course Syllabus Displayed on individual course pages
 *
 * @author 		LifterLMS
 * @package 	LifterLMS/Templates
 * @since       1.0.0
 * @version     3.0.0 - refactored for sanity's sake
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// cease execution if the course outline is disabled
if ( get_option( 'lifterlms_course_display_outline' ) === 'no' ) {
	return;
}

global $post, $course;

// get options
$display_titles = get_option( 'lifterlms_course_display_outline_titles', 'yes' );

// retrieve sections to use in the template
$sections = $course->get_children_sections();
?>

<div class="clear"></div>

<div class="llms-syllabus-wrapper">

	<?php if ( ! $sections ) : ?>

		<?php _e( 'This course does not have any sections.', 'lifterlms' ); ?>

	<?php else : ?>

		<?php foreach ( $sections as $s ) : $section = new LLMS_Section( $s->ID ); ?>

			<?php if ( 'yes' === $display_titles ) : ?>
				<h3 class="llms-h3 llms-section-title"><?php echo get_the_title( $s->ID ); ?></h3>
			<?php endif; ?>

			<?php if ( $lessons = $section->get_children_lessons() ) : ?>

				<?php foreach ( $lessons as $l ) : ?>

					<?php llms_get_template( 'course/lesson-preview.php', array(
						'lesson' => new LLMS_Lesson( $l->ID ),
						'total_lessons' => count( $lessons ),
					) ); ?>

				<?php endforeach; ?>

			<?php else : ?>

				<?php _e( 'This section does not have any lessons.', 'lifterlms' ); ?>

			<?php endif; ?>

		<?php endforeach; ?>

	<?php endif; ?>

	<div class="clear"></div>
</div>
