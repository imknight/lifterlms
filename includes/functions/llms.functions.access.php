<?php
/**
 * Functions used for managing page / post access
 *
 * @package LifterLMS/Functions
 *
 * @since 1.0.0
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determine if a WP_Post is accessible to a given user
 *
 * Called during "template_include" to determine if redirects
 * or template overrides are in order.
 *
 * @since 1.0.0
 * @since 3.16.11 Unknown.
 * @since [version] Simplified default variable fallbacks.
 *
 * @param int      $post_id WordPress Post ID of the content.
 * @param int|null $user_id Optional. WP User ID. Defaults to the current user if none supplied.
 * @return array {
 *     Hash of restriction information.
 *
 *     @type int    $content_id     WP_Post ID of the requested content post.
 *     @type int    $restriction_id WP_Post ID of the post governing restriction over the requested content.
 *     @type bool   $is_restricted  Whether or not the requested content is accessible by the requested user.
 *     @type string $reason         A code describing the reason why the requested content is restricted.
 * }
 */
function llms_page_restricted( $post_id, $user_id = null ) {

	$user_id = $user_id ? $user_id : get_current_user_id();
	$student = $user_id ? llms_get_student( $user_id ) : false;

	$post_type = get_post_type( $post_id );

	$results = array(
		'content_id'     => $post_id,
		'is_restricted'  => false,
		'reason'         => 'accessible',
		'restriction_id' => 0,
	);

	/**
	 * Do checks to determine if the content should be restricted.
	 */
	$sitewide_membership_id = llms_is_post_restricted_by_sitewide_membership( $post_id, $user_id );
	$membership_id          = llms_is_post_restricted_by_membership( $post_id, $user_id );

	if ( is_home() && $sitewide_membership_id ) {
		$restriction_id = $sitewide_membership_id;
		$reason         = 'sitewide_membership';
		// if it's a search page and the site isn't restricted to a membership bypass restrictions.
	} elseif ( ( is_search() ) && ! get_option( 'lifterlms_membership_required', '' ) ) {
		return apply_filters( 'llms_page_restricted', $results, $post_id );
	} elseif ( is_singular() && $sitewide_membership_id ) {
		$restriction_id = $sitewide_membership_id;
		$reason         = 'sitewide_membership';
	} elseif ( is_singular() && $membership_id ) {
		$restriction_id = $membership_id;
		$reason         = 'membership';
	} elseif ( is_singular() && 'lesson' === $post_type ) {
		$lesson = new LLMS_Lesson( $post_id );
		// if lesson is free, return accessible results and skip the rest of this function.
		if ( $lesson->is_free() ) {
			return $results;
		} else {
			$restriction_id = $lesson->get_parent_course();
			$reason         = 'enrollment_lesson';
		}
	} elseif ( is_singular() && 'course' === $post_type ) {
		$restriction_id = $post_id;
		$reason         = 'enrollment_course';
	} elseif ( is_singular() && 'llms_membership' === $post_type ) {
		$restriction_id = $post_id;
		$reason         = 'enrollment_membership';
	} else {

		/**
		 * Allow filtering of results before checking if the student has access.
		 *
		 * @since Unknown.
		 *
		 * @param array $results Restriction check result data.
		 * @param int   $post_id WordPress Post ID of the content.
		 */
		$results = apply_filters( 'llms_page_restricted_before_check_access', $results, $post_id );
		extract( $results ); // phpcs:ignore

	}

	/**
	 * Content should be restricted, so we'll do the restriction checks
	 * and return restricted results.
	 *
	 * This is run if we have a restriction and a reason for restriction
	 * and we either don't have a logged in student or the logged in student doesn't have access.
	 */
	if ( ! empty( $restriction_id ) && ! empty( $reason ) && ( ! $student || ! $student->is_enrolled( $restriction_id ) ) ) {

		$results['is_restricted']  = true;
		$results['reason']         = $reason;
		$results['restriction_id'] = $restriction_id;

		/**
		 * Allow filtering of the restricted results.
		 *
		 * @since Unknown
		 *
		 * @param array $results Restriction check result data.
		 * @param int   $post_id WordPress Post ID of the content.
		 */
		return apply_filters( 'llms_page_restricted', $results, $post_id );

	}

	/**
	 * At this point student has access or the content isn't supposed to be restricted
	 * we need to do some additional checks for specific post types.
	 */
	if ( is_singular() ) {

		if ( 'llms_quiz' === $post_type ) {

			$quiz_id = llms_is_quiz_accessible( $post_id, $user_id );
			if ( $quiz_id ) {

				$results['is_restricted']  = true;
				$results['reason']         = 'quiz';
				$results['restriction_id'] = $post_id;
				/* This filter is documented above. */
				return apply_filters( 'llms_page_restricted', $results, $post_id );

			}
		}

		if ( 'lesson' === $post_type || 'llms_quiz' === $post_type ) {

			$course_id = llms_is_post_restricted_by_time_period( $post_id, $user_id );
			if ( $course_id ) {

				$results['is_restricted']  = true;
				$results['reason']         = 'course_time_period';
				$results['restriction_id'] = $course_id;
				/* This filter is documented above. */
				return apply_filters( 'llms_page_restricted', $results, $post_id );

			}

			$prereq_data = llms_is_post_restricted_by_prerequisite( $post_id, $user_id );
			if ( $prereq_data ) {

				$results['is_restricted']  = true;
				$results['reason']         = sprintf( '%s_prerequisite', $prereq_data['type'] );
				$results['restriction_id'] = $prereq_data['id'];
				/* This filter is documented above. */
				return apply_filters( 'llms_page_restricted', $results, $post_id );

			}

			$lesson_id = llms_is_post_restricted_by_drip_settings( $post_id, $user_id );
			if ( $lesson_id ) {

				$results['is_restricted']  = true;
				$results['reason']         = 'lesson_drip';
				$results['restriction_id'] = $lesson_id;
				/* This filter is documented above. */
				return apply_filters( 'llms_page_restricted', $results, $post_id );

			}
		}
	}

	/* This filter is documented above. */
	return apply_filters( 'llms_page_restricted', $results, $post_id );

}

/**
 * Retrieve a list of memberships that a given post is restricted to
 *
 * @since [version]
 *
 * @param int $post_id WP_Post ID of the post.
 * @return int[] List of the WP_Post IDs of llms_membership post types. An empty array signifies the post
 *               has no restrictions.
 */
function llms_get_post_membership_restrictions( $post_id ) {

	$memberships = array();

	/**
	 * Filter the post types which cannot be restricted to a membership.
	 *
	 * These LifterLMS core post types are restricted via enrollment into that
	 * post (or it's parent post) directly so there won't be any related
	 * memberships for these post types.
	 *
	 * @since Unknown
	 *
	 * @param string[] $post_types Array of post type names.
	 */
	$skip_post_types = apply_filters(
		'llms_is_post_restricted_by_membership_skip_post_types',
		array(
			'course',
			'lesson',
			'llms_quiz',
			'llms_membership',
			'llms_question',
			'llms_certificate',
			'llms_my_certificate',
		),
	);

	if ( ! in_array( get_post_type( $post_id ), $skip_post_types, true ) ) {
		$saved_ids = get_post_meta( $post_id, '_llms_restricted_levels', true );
		if ( llms_parse_bool( get_post_meta( $post_id, '_llms_is_restricted', true ) ) && is_array( $saved_ids ) ) {
			$memberships = array_map( 'absint', $saved_ids );
		}
	}

	/**
	 * Filter the list the membership restrictions for a given post.
	 *
	 * @since [version]
	 *
	 * @param int[] $memberships List of the WP_Post IDs of llms_membership post types.
	 * @param int   $post_id     WP_Post ID of the restricted post.
	 */
	return apply_filters( 'llms_get_post_membership_restrictions', $memberships, $post_id );

}

/**
 * Retrieve a message describing the reason why content is restricted.
 * Accepts an associative array of restriction data that can be retrieved from llms_page_restricted().
 *
 * This function doesn't handle all restriction types but it should in the future.
 * Currently it's being utilized for tooltips on lesson previews and some messages
 * output during LLMS_Template_Loader handling redirects.
 *
 * @since 3.2.4
 * @since 3.16.12 Unknown.
 *
 * @param array $restriction Array of data from `llms_page_restricted()`.
 * @return string
 */
function llms_get_restriction_message( $restriction ) {

	$msg = __( 'You do not have permission to access this content', 'lifterlms' );

	switch ( $restriction['reason'] ) {

		case 'course_prerequisite':
			$lesson      = new LLMS_Lesson( $restriction['content_id'] );
			$course_id   = $restriction['restriction_id'];
			$prereq_link = '<a href="' . get_permalink( $course_id ) . '">' . get_the_title( $course_id ) . '</a>';
			$msg         = sprintf(
				/* Translators: %$1s = lesson title; %2$s link of the course prerequisite */
				_x(
					'The lesson "%1$s" cannot be accessed until the required prerequisite course "%2$s" is completed.',
					'restricted by course prerequisite message',
					'lifterlms'
				),
				$lesson->get( 'title' ),
				$prereq_link
			);
			break;

		case 'course_track_prerequisite':
			$lesson      = new LLMS_Lesson( $restriction['content_id'] );
			$track       = new LLMS_Track( $restriction['restriction_id'] );
			$prereq_link = '<a href="' . $track->get_permalink() . '">' . $track->term->name . '</a>';
			$msg         = sprintf(
				/* Translators: %$1s = lesson title; %2$s link of the track prerequisite */
				_x(
					'The lesson "%1$s" cannot be accessed until the required prerequisite track "%2$s" is completed.',
					'restricted by course track prerequisite message',
					'lifterlms'
				),
				$lesson->get( 'title' ),
				$prereq_link
			);
			break;

		// this particular case is only utilized by lessons, courses do the check differently in the template.
		case 'course_time_period':
			$course = new LLMS_Course( $restriction['restriction_id'] );
			// if the start date hasn't passed yet.
			if ( ! $course->has_date_passed( 'start_date' ) ) {
				$msg = $course->get( 'course_opens_message' );
			} elseif ( $course->has_date_passed( 'end_date' ) ) {
				$msg = $course->get( 'course_closed_message' );
			}
			break;

		case 'enrollment_lesson':
			$course = new LLMS_Course( $restriction['restriction_id'] );
			$msg    = $course->get( 'content_restricted_message' );
			break;

		case 'lesson_drip':
			$lesson = new LLMS_Lesson( $restriction['restriction_id'] );
			$msg    = sprintf(
				/* Translators: %$1s = lesson title; %2$s available date */
				_x(
					'The lesson "%1$s" will be available on %2$s',
					'lesson restricted by drip settings message',
					'lifterlms'
				),
				$lesson->get( 'title' ),
				$lesson->get_available_date()
			);
			break;

		case 'lesson_prerequisite':
			$lesson        = new LLMS_Lesson( $restriction['content_id'] );
			$prereq_lesson = new LLMS_Lesson( $restriction['restriction_id'] );
			$prereq_link   = '<a href="' . get_permalink( $prereq_lesson->get( 'id' ) ) . '">' . $prereq_lesson->get( 'title' ) . '</a>';
			$msg           = sprintf(
				/* Translators: %$1s = lesson title; %2$s link of the lesson prerequisite */
				_x(
					'The lesson "%1$s" cannot be accessed until the required prerequisite "%2$s" is completed.',
					'lesson restricted by prerequisite message',
					'lifterlms'
				),
				$lesson->get( 'title' ),
				$prereq_link
			);
			break;

		default:
	}

	/**
	 * Allow filtering the restriction message.
	 *
	 * @since Unknown
	 *
	 * @param string $msg         Restriction message.
	 * @param array  $restriction Array of data from `llms_page_restricted()`.
	 */
	return apply_filters( 'llms_get_restriction_message', do_shortcode( $msg ), $restriction );
}

/**
 * Get a boolean out of llms_page_restricted for easy if checks.
 *
 * @since 3.0.0
 * @since 3.37.10 Made `$user_id` parameter optional. Default is `null`.
 *
 * @param int      $post_id WordPress Post ID of the content.
 * @param int|null $user_id Optional. WP User ID (will use get_current_user_id() if none supplied). Default `null`.
 * @return bool
 */
function llms_is_page_restricted( $post_id, $user_id = null ) {
	$restrictions = llms_page_restricted( $post_id, $user_id );
	return $restrictions['is_restricted'];
}

/**
 * Determine if a lesson/quiz is restricted by drip settings.
 *
 * @since 3.0.0
 * @since 3.16.11 Unknown.
 * @since 3.37.10 Use strict comparison '===' in place of '=='.
 *
 * @param int      $post_id WP Post ID of a lesson or quiz.
 * @param int|null $user_id Optional. WP User ID (will use get_current_user_id() if none supplied). Default `null`.
 * @return int|false False if the lesson is available.
 *                   WP Post ID of the lesson if it is not.
 */
function llms_is_post_restricted_by_drip_settings( $post_id, $user_id = null ) {

	$post_type = get_post_type( $post_id );

	// if we're on a lesson, lesson id is the post id.
	if ( 'lesson' === $post_type ) {
		$lesson_id = $post_id;
	} elseif ( 'llms_quiz' === $post_type ) {
		$quiz      = llms_get_post( $post_id );
		$lesson_id = $quiz->get( 'lesson_id' );
		if ( ! $lesson_id ) {
			return false;
		}
	} else {
		// dont pass other post types in here dumb dumb.
		return false;
	}

	$lesson = new LLMS_Lesson( $lesson_id );

	if ( $lesson->is_available() ) {
		return false;
	} else {
		return $lesson_id;
	}

}

/**
 * Determine if a lesson/quiz is restricted by a prerequisite lesson.
 *
 * @since 3.0.0
 * @since 3.16.11 Unknown.
 *
 * @param int      $post_id WP Post ID of a lesson or quiz.
 * @param int|null $user_id Optional. WP User ID (will use get_current_user_id() if none supplied). Default `null`.
 * @return array|false False if the post is not restricted or the user has completed the prereq
 *                     associative array with prereq type and prereq id
 *                     array(
 *                         type => [course|course_track|lesson]
 *                         id => int (object id)
 *                     ).
 */
function llms_is_post_restricted_by_prerequisite( $post_id, $user_id = null ) {

	$post_type = get_post_type( $post_id );

	if ( 'lesson' === $post_type ) {
		$lesson_id = $post_id;
	} elseif ( 'llms_quiz' === $post_type ) {
		$quiz      = llms_get_post( $post_id );
		$lesson_id = $quiz->get( 'lesson_id' );
		if ( ! $lesson_id ) {
			return false;
		}
	} else {
		return false;
	}

	$lesson = llms_get_post( $lesson_id );
	$course = $lesson->get_course();

	if ( ! $course ) {
		return false;
	}

	// get an array of all possible prereqs.
	$prerequisites = array();

	if ( $course->has_prerequisite( 'course' ) ) {
		$prerequisites[] = array(
			'id'   => $course->get_prerequisite_id( 'course' ),
			'type' => 'course',
		);
	}

	if ( $course->has_prerequisite( 'course_track' ) ) {
		$prerequisites[] = array(
			'id'   => $course->get_prerequisite_id( 'course_track' ),
			'type' => 'course_track',
		);
	}

	if ( $lesson->has_prerequisite() ) {
		$prerequisites[] = array(
			'id'   => $lesson->get_prerequisite(),
			'type' => 'lesson',
		);
	}

	// prereqs exist and user is not logged in, return the first prereq id.
	if ( $prerequisites && ! $user_id ) {

		return array_shift( $prerequisites );

		// if incomplete, send the prereq id.
	} else {

		$student = new LLMS_Student( $user_id );
		foreach ( $prerequisites as $prereq ) {
			if ( ! $student->is_complete( $prereq['id'], $prereq['type'] ) ) {
				return $prereq;
			}
		}
	}

	// otherwise return false.
	// no prereq.
	return false;

}

/**
 * Determine if a course (or lesson/quiz) is "open" according to course time period settings.
 *
 * @since 3.0.0
 * @since 3.16.11 Unknown.
 *
 * @param int      $post_id WP Post ID of a course, lesson, or quiz.
 * @param int|null $user_id Optional. WP User ID (will use get_current_user_id() if none supplied). Default `null`.
 * @return int|false False if the post is not restricted by course time period,
 *                   WP Post ID of the course if it is.
 */
function llms_is_post_restricted_by_time_period( $post_id, $user_id = null ) {

	$post_type = get_post_type( $post_id );

	// if we're on a lesson, get course information.
	if ( 'lesson' === $post_type ) {

		$lesson    = new LLMS_Lesson( $post_id );
		$course_id = $lesson->get_parent_course();

	} elseif ( 'llms_quiz' === $post_type ) {
		$quiz      = llms_get_post( $post_id );
		$lesson_id = $quiz->get( 'lesson_id' );
		if ( ! $lesson_id ) {
			return false;
		}
		$lesson = llms_get_post( $lesson_id );
		if ( ! $lesson_id ) {
			return false;
		}
		$course_id = $lesson->get_parent_course();

	} elseif ( 'course' === $post_type ) {

		$course_id = $post_id;

	} else {

		return false;

	}

	$course = new LLMS_Course( $course_id );
	if ( $course->is_open() ) {
		return false;
	} else {
		return $course_id;
	}

}

/**
 * Determine if a WordPress post (of any type) is restricted to at least one LifterLMS Membership level.
 *
 * This function replaces the now deprecated page_restricted_by_membership() (and has slightly different functionality).
 *
 * @since 3.0.0
 * @since 3.16.14 Unknown.
 * @since 3.37.10 Call `in_array()` with strict comparison.
 *
 * @param int      $post_id WP_Post ID.
 * @param int|null $user_id Optional. WP User ID (will use get_current_user_id() if none supplied). Default `null`.
 * @return bool|int WP_Post ID of the membership if a restriction is found.
 *                  False if no restrictions found.
 */
function llms_is_post_restricted_by_membership( $post_id, $user_id = null ) {

	$restriction = false;

	$memberships = llms_get_post_membership_restrictions( $post_id );
	if ( $memberships ) {

		$student = $user_id ? llms_get_student( $user_id ) : false;
		if ( ! $student ) {
			$restriction = array_shift( $memberships );
		} else {

			/**
			 * Reverse to ensure a user enrolled in none of the memberships,
			 * encounters the same restriction settings as a visitor.
			 */
			foreach ( array_reverse( $memberships ) as $mid ) {

				// Set this as the restriction id.
				$restriction = $mid;

				/*
				 * Once we find the student has access break the loop,
				 * this will be the restriction that the template loader will check against later.
				 */
				if ( $student->is_enrolled( $mid ) ) {
					break;
				}
			}
		}
	}

	/**
	 * Filter the result of `llms_is_post_restricted_by_sitewide_membership()`
	 *
	 * @since [version]
	 *
	 * @param bool|int $restriction Restriction result. WP_Post ID of the membership or `false` when there's no restriction.
	 * @param int      $post_id     WP_Post ID of the requested post.
	 * @param int|null $user_id     WP_User ID of the requested user.
	 */
	return apply_filters( 'llms_is_post_restricted_by_membership', $restriction, $post_id, $user_id );


}

/**
 * Determine if a post should bypass sitewide membership restrictions.
 *
 * If sitewide membership restriction is disabled, this will always return false.
 * This function replaces the now deprecated site_restricted_by_membership() (and has slightly different functionality).
 *
 * @since 3.0.0
 * @since 3.37.10 Do not apply membership restrictions on the page set as membership's restriction redirect page.
 *                  Exclude the privacy policy from the sitewide restriction.
 *                  Call `in_array()` with strict comparison.
 * @since [version] Refactored to reduce complexity and remove nested conditions.
 *              Added filter on return.
 *
 * @param int      $post_id WP Post ID.
 * @param int|null $user_id Optional. WP User ID (will use get_current_user_id() if none supplied). Default `null`.
 * @return bool|int If the post is not restricted (or there are not sitewide membership restrictions) returns false.
 *                  If the post is restricted, returns the membership id required.
 */
function llms_is_post_restricted_by_sitewide_membership( $post_id, $user_id = null ) {

	// Return value.
	$restriction = false;

	$membership_id = absint( get_option( 'lifterlms_membership_required', '' ) );
	$membership    = $membership_id ? llms_get_post( $membership_id ) : false;

	// site is restricted to a membership.
	if ( $membership && is_a( $membership, 'LLMS_Membership' ) ) {

		// Restricted contents redirection page id, if any.
		$redirect_page_id = 'page' === $membership->get( 'restriction_redirect_type' ) ? absint( $membership->get( 'redirect_page_id' ) ) : 0;

		$bypass_ids = array(
			$membership_id, // The membership page the site is restricted to.
			get_option( 'lifterlms_terms_page_id' ), // Terms and conditions.
			llms_get_page_id( 'memberships' ), // Membership archives.
			llms_get_page_id( 'myaccount' ), // Student dashboard.
			llms_get_page_id( 'checkout' ), // Checkout page.
			get_option( 'wp_page_for_privacy_policy' ), // WP Core privacy policy page.
			$redirect_page_id, // Restricted contents redirection page id.
		);

		$bypass_ids = array_filter( array_map( 'absint', $bypass_ids ) );

		/**
		 * Filter a list of sitewide membership restriction post IDs.
		 *
		 * Any post id found in this will be accessible regardless of user enrollment into the
		 * site's sitewide membership restriction.
		 *
		 * Note: Post IDs are evaluated with a strict comparator. When filtering ensure that
		 * additional IDs are added to the array as integers, not numeric strings!
		 *
		 * @since Unknown
		 *
		 * @param int[] $bypass_ids Array of WP_Post IDs.
		 */
		$allowed = apply_filters( 'lifterlms_sitewide_restriction_bypass_ids', $bypass_ids );

		$restriction = in_array( $post_id, $allowed, true ) ? false : $membership_id;

	}

	/**
	 * Filter the result of `llms_is_post_restricted_by_sitewide_membership()`
	 *
	 * @since [version]
	 *
	 * @param bool|int $restriction Restriction result. WP_Post ID of the sitewide membership or `false` when there's no restriction.
	 * @param int      $post_id     WP_Post ID of the requested post.
	 * @param int|null $user_id     WP_User ID of the requested user.
	 */
	return apply_filters( 'llms_is_post_restricted_by_sitewide_membership', $restriction, $post_id, $user_id );

}

/**
 * Determine if a quiz should be accessible by a user.
 *
 * @since 3.1.6
 * @since 3.16.1 Unknown.
 *
 * @param int      $post_id WP Post ID.
 * @param int|null $user_id Optional. WP User ID (will use get_current_user_id() if none supplied). Default `null`.
 * @return bool|int If the post is not restricted returns false.
 *                  If the post is restricted, returns the quiz id.
 */
function llms_is_quiz_accessible( $post_id, $user_id = null ) {

	$quiz      = llms_get_post( $post_id );
	$lesson_id = $quiz->get( 'lesson_id' );

	// no lesson or the user is not enrolled.
	if ( ! $lesson_id || ! llms_is_user_enrolled( $user_id, $lesson_id ) ) {
		return $post_id;
	}

	return false;

}
