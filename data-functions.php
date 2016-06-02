<?php

/**
 * Determine if a date is between two other dates
 *
 * @param string $compare_date The date to compare
 * @param string $from_date The lower bounding date
 * @param string $to_date The upper bounding date
 *
 * @return bool True if date is in range, false if not
 */
function atlas_is_in_date_range( $compare_date, $from_date, $to_date ) {
	$compare_date = strtotime( $compare_date );
	$from_date = strtotime( $from_date );
	$to_date = strtotime( $to_date );

	if ( $compare_date >= $from_date && $compare_date <= $to_date ) {
		return true;
	} else {
		return false;
	}
}

/**
 * Get the count of days between two dates
 *
 * @param string $from_date The lower bounding date
 * @param string $to_date The upper bounding date
 *
 * @uses absint()
 *
 * @return int Number of days
 */
function atlas_number_days_in_date_range( $from_date, $to_date ) {
	$from_date = strtotime( $from_date );
	$to_date = strtotime( $to_date );

	$days = ( absint( $to_date - $from_date ) / DAY_IN_SECONDS ) + 1;

	return $days;
}

/**
 * Get an array of dates between two dates 
 *
 * @param string $date_from The lower bounding date
 * @param string $date_to The upper bounding date
 * @param string $format The PHP date format to return
 *
 * @uses DateTime
 * @uses DateInterval
 * @uses DatePeriod
 * 
 * @return array List of dates in specified format
 */
function atlas_get_dates_between( $date_from, $date_to, $format = 'Y-m-d' ) {
	$date_from = new DateTime( $date_from );
	$date_to = new DateTime( date( 'Y-m-d', strtotime( $date_to ) + DAY_IN_SECONDS ) );
	$interval = new DateInterval( 'P1D' );
	$date_range = new DatePeriod( $date_from, $interval, $date_to );

	$range = array();

	foreach ( $date_range as $date ) {
		$range[] = $date->format( $format );
	}

	return $range;
}

/**
 * Get a list of the first day in a month for x number of months back
 *
 * @param int $m Number of months
 *
 * @return array List of first days of previous months
 */
function atlas_get_previous_months( $m ) {
	$months = array();

	for ( $i = 1; $i <= absint( $m ); $i++ ) {
		$months[] = date( 'Y-m', strtotime( date( 'Y-m-01' ) . " -$i months" ) );
	}

	return $months;
}

/**
 * Get all of the dates in a specified year/month
 *
 * @param string $ym The year and month to get dates in
 *
 * @return array List of dates in the specified month
 */
function atlas_get_dates_in_month( $ym ) {
	$list = array();

	$month = date( 'm', strtotime( $ym ) );
	$year = date( 'Y', strtotime( $ym ) );

	for ( $d = 1; $d <= 31; $d++ ) {
		$time = mktime( 12, 0, 0, $month, $d, $year );

		if ( $month == date( 'm', $time ) ) {
			$list[] = date( 'Y-m-d', $time );
		}
	}

	return $list;
}

/**
 * Get a list of months x-number of months back
 *
 * @param int $months Number of months back
 * @param bool $carrier True to make this a multidimensional array with room for data
 * 
 * @return array List of dates in specified format
 */
function atlas_get_month_date_array( $months, $carrier = false ) {
	$data = array();
	$months = array_reverse( atlas_get_previous_months( $months ) );

	foreach ( $months as $m ) {
		$data[$m] = atlas_get_dates_in_month( $m );
	}

	if ( $carrier ) {
		$carrier = array();

		foreach ( $data as $month => $dates ) {
			foreach ( $dates as $date ) {
				$carrier[$month][$date] = 0;
			}
		}

		return $carrier;
	}

	return $data;
}

/**
 * Get an array of months in chronological order
 *
 * @param int $months How many months to get
 * 
 * @uses atlas_get_previous_months()
 *
 * @return array List of months
 */
function atlas_get_month_array( $months ) {
	$data = array();
	$months = array_reverse( atlas_get_previous_months( $months ) );

	return $months;
}

/**
 * Get the start and end date of a month
 *
 * @param string $month The month to get start and end dates for
 *
 * @uses atlas_get_dates_in_month()
 * 
 * @return array Start and end dates in an array
 */
function atlas_get_month_date_start_end( $month ) {
	$dates = atlas_get_dates_in_month( $month );

	return array( min( $dates ), max( $dates ) );
}
