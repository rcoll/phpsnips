<?php

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Atlas WordPress CLI Command
	 *
	 * Use this CLI to pull data from various APIs into WordPress, including Google Analytics,  
	 * Parse.ly, Google Drive, SalesForce, and more.
	 */
	class Atlas_CLI_Command extends WP_CLI_Command {

		protected $settings = array(
			'google_service_client_id'     => '', 
			'google_service_email_address' => '', 
			'google_service_pkf'           => '', 
			'google_service_app_name'      => '', 
			'google_service_key_file'      => '', 
			'google_analytics_accounts'    => array( 
				// Array: Account Name, Account ID, Profile ID
				array( 'Some Site Name', '1234567', 'UA-123456-1' ), 
				array( 'Some Other Site', '9876543', 'UA-987654-1' ), 
			), 
			'google_drive_files'           => array( 
				// Array: Document Name, File Key, Sheet ID
				array( 'Some Document', '1wKzL1aa4zdPl9_5bVcifg3EwkKg1xIkyWBNxH8U8ruk', '0' ), 
				array( 'Another Doc', '1wKzL1aa4zdPl9_5bVcifg3EwkKg1xIkyWBNxH8U8ruk', '123456' ), 
			), 
			'google_dfp_network_id' => '1003829', 
			'salesforce_consumer_key' => '', 
			'salesforce_consumer_secret' => '', 
			'salesforce_callback_url' => '', 
			'salesforce_start_url' => '', 
			'salesforce_username' => '', 
			'salesforce_password' => '', 
			'facebook_app_id' => '', 
			'facebook_app_secret' => '', 
			'outbrain_username' => '', 
			'outbrain_password' => '', 
			'parsely_api_key' => 'somesite.com', 
			'parsely_api_secret' => '', 
			'notification_emails' => array( 
				'some1@somewhere.com', 
				'some1else@somewhere.com', 
			), 
		);

		protected $google_api_client_analytics         = null,
		          $google_api_client_youtube_analytics = null, 
		          $google_api_client_youtube_data      = null, 
		          $google_api_client_gdrive            = null,
		          $google_api_client_insights          = null,
		          $google_api_client_webmasters        = null;

		public function run_hourly_tasks() {

		}

		public function run_daily_tasks() {

		}

		/**
		 * Get the service token from the Google API
		 */
		protected function setup_google_service_client( $service = null ) {
			if ( ( function_exists( 'session_status' ) && PHP_SESSION_NONE == session_status() ) && ( function_exists( 'headers_sent' ) && ! headers_sent() ) ) {
				session_start();
			}

			$key = file_get_contents( $this->settings['google_service_key_file'] );

			$api_client = new Google_Client();
			$api_client->setApplicationName( $this->settings['google_service_app_name'] );

			if ( 'youtube-analytics' == $service ) {
				$this->google_api_client_youtube_analytics = new Google_Service_YouTubeAnalytics( $api_client );
			} elseif ( 'youtube-data' == $service ) {
				$this->google_api_client_youtube_data = new Google_Service_YouTube( $api_client );
			} elseif ( 'drive' == $service ) {
				$this->google_api_client_gdrive = new Google_Service_Drive( $api_client );
			} elseif ( 'insights' == $service ) {
				$this->google_api_client_insights = new Google_Service_Pagespeedonline( $api_client );
			} elseif ( 'webmasters' == $service ) {
				$this->google_api_client_webmasters = new Google_Service_Webmasters( $api_client );
				$this->google_api_client_analytics = new Google_Service_Analytics( $api_client );
			} elseif ( 'analytics' == $service ) {
				$this->google_api_client_analytics = new Google_Service_Analytics( $api_client );
			}

			if ( isset( $_SESSION['service_token'] ) ) {
				$api_client->setAccessToken( $_SESSION['service_token'] );
			}

			$cred = new Google_Auth_AssertionCredentials(
				$this->settings['google_service_email_address'] ), 
				array( 
					'https://www.googleapis.com/auth/analytics.readonly', 
					'https://www.googleapis.com/auth/yt-analytics.readonly', 
					'https://www.googleapis.com/auth/yt-analytics-monetary.readonly', 
					'https://www.googleapis.com/auth/youtubepartner', 
					'https://www.googleapis.com/auth/youtube.readonly', 
					'https://www.googleapis.com/auth/youtube', 
					'https://www.googleapis.com/auth/drive', 
					'https://www.googleapis.com/auth/drive.apps.readonly', 
					'https://www.googleapis.com/auth/drive.file', 
					'https://www.googleapis.com/auth/drive.metadata.readonly', 
					'https://www.googleapis.com/auth/drive.readonly', 
					'https://www.googleapis.com/auth/webmasters.readonly', 
				),
				$key
			);
			
			$api_client->setAssertionCredentials( $cred );

			if ( $api_client->getAuth()->isAccessTokenExpired() ) {
				$api_client->getAuth()->refreshTokenWithAssertion( $cred );
			}

			$_SESSION['service_token'] = $api_client->getAccessToken();
		}

		/**
		 * Make an API call to the Parse.ly API
		 */
		protected function parsely_api_call_realtime( $args ) {
			$args = wp_parse_args( $args, array( 
				'api'       => 'realtime', 
				'date_from' => date( 'Y-m-d', current_time( 'U' ) ), 
				'date_to'   => date( 'Y-m-d', current_time( 'U' ) ), 
				'time'      => '1h', 
				'site'      => '', 
				'limit'     => 10, 
				'page'      => 1, 
				'section'   => false, 
			));

			$cache_key = md5( 'parsely_api_call_realtime_' . serialize( $args ) );

			$results = wp_cache_get( $cache_key, 'atlas' );

			if ( $results ) { 
				return $results;
			}

			$api_key = $this->settings['parsely_api_key'];
			$api_secret = $this->settings['parsely_api_secret'];

			if ( 'realtime' == $args['api'] ) {
				$api_url = 'http://api.parsely.com/v2/realtime/posts?' . http_build_query( array( 
					'apikey' => urlencode( $api_key ), 
					'secret' => urlencode( $api_secret ), 
					'time' => urlencode( $args['time'] ), 
					'limit' => absint( $args['limit'] ), 
					'page' => absint( $args['page'] ), 
				));

				if ( $args['section'] ) {
					$api_url .= '&section=' . urlencode( $args['section'] );
				}
			}

			if ( ! $api_url ) {
				return false;
			}

			$response = wp_remote_get( $api_url );
			$results = json_decode( $response['body'] );

			wp_cache_set( $cache_key, $results, 'atlas', 3600 );

			return $results;
		}

		/**
		 * Get a consumer token from the Outbrain API
		 */
		protected function get_outbrain_token( $credentials, $force_new = false ) {
			// Try to retrieve our token from a transient
			$token = get_transient( 'atlas_outbrain_token' );

			// Return the token if we are not forcing a new one
			if ( $token && ! $force_new )
				return $token;

			// Make a curl call to the API url
			$c = curl_init( 'https://api.outbrain.com/amplify/v0.1/login' );
			curl_setopt( $c, CURLOPT_USERPWD, $credentials[0] . ':' . $credentials[1] );
			curl_setopt( $c, CURLOPT_TIMEOUT, 30 );
			curl_setopt( $c, CURLOPT_RETURNTRANSFER, TRUE );

			$response = curl_exec( $c );
			curl_close( $c );

			$result = json_decode( str_replace( 'OB-TOKEN-V1', 'OB_TOKEN_V1', $response ) );
			$token = $result->OB_TOKEN_V1;

			if ( ! $token )
				return false;

			// Store the token in our database for one week
			set_transient( 'atlas_outbrain_token', $token, 604800 );

			return $token;
		}

		/**
		 * Make an API call to the Outbrain Amplify API
		 */
		protected function outbrain_api_call( $args = array() ) {
			$args = wp_parse_args( $args, array( 
				'method' => null, 
			));

			if ( ! $args['method'] ) {
				return false;
			}

			$token = $this->get_outbrain_token( array( $this->settings['outbrain_username'], $this->settings['outbrain_password'] ) );

			$c = curl_init( "https://api.outbrain.com/amplify/v0.1/{$args['method']}" );
			curl_setopt( $c, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $c, CURLOPT_HEADER, false );
			curl_setopt( $c, CURLOPT_HTTPHEADER, array( "OB-TOKEN-V1: $token" ) );

			$response = curl_exec( $c );
			curl_close( $c );

			return json_decode( $response );
		}

		/**
		 * Get share/like/save counts for a URL from various social networks
		 */
		protected function get_url_social_data( $url ) {
			$url = esc_url_raw( $url );

			$cache_key = md5( 'get_url_social_data_' . $url );

			$data = wp_cache_get( $cache_key, 'atlas' );

			if ( $data ) {
				return $data
			}

			$data = array();

			$response = wp_remote_get( "https://graph.facebook.com/fql?q=SELECT%20like_count,%20total_count,%20share_count,%20click_count,%20comment_count%20FROM%20link_stat%20WHERE%20url%20=%20%22$url%22" );
			if ( 200 == $response['response']['code'] ) {
				$results = json_decode( $response['body'] );
				$data['facebook_likes'] = absint( $results->data[0]->like_count );
				$data['facebook_shares'] = absint( $results->data[0]->share_count );
				$data['facebook_clicks'] = absint( $results->data[0]->click_count );
				$data['facebook_comments'] = absint( $results->data[0]->comment_count );
			}

			/*
			$response = wp_remote_get( "http://urls.api.twitter.com/1/urls/count.json?url=$url" );
			if ( 200 == $response['response']['code'] ) {
				$results = json_decode( $response['body'] );
				$data['twitter_tweets'] = absint( $results->count );
			}
			*/

			$response = wp_remote_get( "http://api.pinterest.com/v1/urls/count.json?url=$url&callback=moguldata" );
			if ( 200 == $response['response']['code'] ) {
				$results = json_decode( str_replace( array( 'moguldata(', ')' ), '', $response['body'] ) );
				$data['pinterest_pins'] = absint( $results->count );
			}

			$response = wp_remote_get( "https://www.linkedin.com/countserv/count/share?format=json&url=$url" );
			if ( 200 == $response['response']['code'] ) {
				$results = json_decode( $response['body'] );
				$data['linkedin_shares'] = absint( $results->count );
			}

			$response = wp_remote_get( "http://www.stumbleupon.com/services/1.01/badge.getinfo?url=$url" );
			if ( 200 == $response['response']['code'] ) {
				$results = json_decode( $response['body'] );
				$data['stumbleupon_views'] = absint( $results->result->views );
			}

			/*
			$response = wp_remote_post( 'https://clients6.google.com/rpc?key=' . GAPI_SERVER_API_KEY, array( 
				'body' => json_encode( array( 
					'method' => 'pos.plusones.get', 
					'id' => 'p', 
					'params' => array( 
						'nolog' => true, 
						'id' => $url, 
						'source' => 'widget', 
						'userId' => '@viewer', 
						'groupId' => '@self', 
					),
					'jsonrpc' => '2.0', 
					'key' => 'p', 
					'apiVersion' => 'v1', 
				)), 
				'headers' => array( 
					'Content-Type' => 'application/json', 
				),
			));
			*/

			wp_cache_set( $cache_key, $data, 'atlas', 3600 );

			return $data;
		}

		/**
		 * Make an API call to the Google Webmasters API
		 */
		protected function google_webmasters_api_call( $args ) {
			$args = wp_parse_args( $args, array( 
				'date_from' => date( 'Y-m-d', strtotime( '1 month ago' ) ), 
				'date_to' => date( 'Y-m-d', strtotime( 'now' ) ), 
				'dimensions' => array( 'query' ), 
				'aggregation_type' => 'auto', 
				'options' => array(), 
				'limit' => false, 
				'url' => 'http://someurl.com', 
			));

			$cache_key = md5( 'google_webmasters_api_call_' . serialize( $args ) );

			$csv = wp_cache_get( $cache_key, 'atlas' );

			if ( $csv ) {
				return $csv;
			}

			$this->setup_google_service_client( 'webmasters' );

			$search = new Google_Service_Webmasters_SearchAnalyticsQueryRequest;
			$search->setStartDate( $args['date_from'] );
			$search->setEndDate( $args['date_to'] );
			$search->setDimensions( $args['dimensions'] );

			if ( $args['limit'] ) {
				$search->setRowLimit( absint( $limit ) );
			}

			$search->setAggregationType( $args['aggregation_type'] );

			try {
				$rows = $this->google_api_client_webmasters->searchanalytics->query( $args['url'], $search, $args['options'] )->getRows();
			} catch ( Google_Service_Exception $e ) {
				$this->dbug( $e );
			}

			if ( isset( $rows ) && ! empty( $rows ) ) {
				$csv = '"Rank","Query","Clicks","Impressions","CTR","Position"' . "\r\n";

				foreach ( $rows as $key => $result ) {
					$columns = array( 
						sanitize_text_field( $key + 1 ), 
						sanitize_text_field( $result->keys[0] ), 
						sanitize_text_field( $result->clicks ), 
						sanitize_text_field( $result->impressions ),
						sanitize_text_field( round( $result->ctr * 100, 2 ) . '%' ), 
						sanitize_text_field( round( $result->position, 1 ) )
					);

					$csv .= '"' . implode( '","', $columns ) . '"' . "\r\n";
				}

				wp_cache_set( $cache_key, $csv, 'atlas', DAY_IN_SECONDS );

				return $csv;
			} else {
				return false;
			}
		}

		protected function google_insights_api_call( $args, $retrying = false ) {
			$args = wp_parse_args( $args, array( 
				'url' => 'http://someurl.com', 
			));

			$url = $args['url'];
			unset( $args['url'] );
			$response = null;

			$this->setup_google_service_client( 'insights' );

			try {
				$response = $this->google_api_client_insights->pagespeedapi->runpagespeed( $url, $args );
			} catch ( Google_IO_Exception $e ) {
				if ( ! $retrying ) {
					$this->dbug( 'Google_IO_Exception - retrying' );
					$newargs = $args;
					$newargs['url'] = $url;
					$response = $this->google_insights_api_call( $newargs, true );
				} else {
					$this->dbug( 'Google_IO_Exception - failed after two tries' );
				}
			} catch ( Google_Service_Exception $e ) {
				if ( ! $retrying ) {
					$this->dbug( 'Google_Service_Exception - retrying' );
					$newargs = $args;
					$newargs['url'] = $url;
					$response = $this->google_insights_api_call( $newargs, true );
				}
			}

			return $response;
		}

		/**
		 * Get file contents from a Google Drive Spreadsheet
		 *
		 * Only tested with spreadsheets, but may work for other Google Doc types as well.
		 */
		protected function google_drive_api_call( $args = array() ) {
			$args = wp_parse_args( $args, array( 
				'file_key' => null, 
				'file_gid' => '0', 
			));

			if ( ! $args['file_key'] ) {
				return false;
			}

			$this->setup_google_service_client( 'drive' );

			$file = false;

			try {
				$file = $this->google_api_client_drive->files->get( $args['file_key'] );

				$url = 'https://docs.google.com/spreadsheets/export?' . http_build_query( array( 
					'id' => $args['file_key'], 
					'exportFormat' => 'tsv', 
					'gid' => $args['file_gid'], 
				));

				$request = new Google_Http_Requeset( $url, 'GET', null, null );
				$httprequest = $this->google_api_client_drive->getClient()->getAuth()->authenticatedRequest( $request );

				if ( 200 == $httprequest->getResponseHttpCode() ) {
					return $httprequest->getResponseBody();
				} else {
					return false;
				}
			} catch ( Exception $e ) {
				$this->dbug( $e );
			}

			return false;
		}

		/**
		 * Make an API call to the Google Analytics API
		 */
		protected function google_analytics_api_call( $args = array() ) {
			$args = wp_parse_args( $args, array( 
				'profile' => '', 
				'date_from' => date( 'Y-m-d', strtotime( 'yesterday' ) ), 
				'date_to' => date( 'Y-m-d', strtotime( 'yesterday' ) ), 
				'metrics' => 'ga:pageviews', 
				'dimensions' => null, 
				'sort' => null, 
				'filters' => null, 
				'max-results' => null, 
			));

			$cache_key = md5( 'google_analytics_api_call_' . serialize( $args ) );

			$results = wp_cache_get( $cache_key, 'atlas' );

			if ( $results ) {
				return $results;
			}

			$extra = array();

			if ( isset( $args['dimensions'] ) ) {
				$extra['dimensions'] = $args['dimensions'];
			}

			if ( isset( $args['sort'] ) ) {
				$extra['sort'] = $args['sort'];
			}

			if ( isset( $args['filters'] ) ) {
				$extra['filters'] = $args['filters'];
			}

			if ( isset( $args['max-results'] ) ) {
				$extra['max-results'] = $args['max-results'];
			}

			if ( count( $extra ) ) {
				try {
					$results = $this->google_api_client_analytics->data_ga->get( $args['profile'], $args['date_from'], $args['date_to'], $args['metrics'], $extra );

					wp_cache_set( $cache_key, $results->getRows(), 'atlas', 3600 );

					return $results->getRows();
				} catch ( Google_Service_Exception $e ) {
					$this->dbug( $e );
				}
			} else {
				try {
					$results = $this->google_api_client_analytics->data_ga->get( $args['profile'], $args['date_from'], $args['date_to'], $args['metrics'] );

					wp_cache_set( $cache_key, $results->getRows(), 'atlas', 3600 );

					return $results->getRows();
				} catch ( Google_Service_Exception $e ) {
					$this->dbug( $e );
				}
			}
		}

		/**
		 * Get curl time data for a URL
		 */
		protected function get_url_performance( $url ) {
			$c = curl_init();

			curl_setopt( $c, CURLOPT_URL, $url );
			curl_setopt( $c, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $c, CURLOPT_CONNECTTIMEOUT, 5 );

			$response = curl_exec( $c );
			$info = curl_getinfo( $c );

			$data = array( 
				'time_total' => $info['total_time'], 
				'time_namelookup' => $info['namelookup_time'], 
				'time_connect' => $info['connect_time'], 
				'time_pretransfer' => $info['pretransfer_time'], 
				'time_starttransfer' => $info['starttransfer_time'], 
				'size_download' => $info['size_download'], 
				'speed_download' => $info['speed_download'], 
			);

			return $data;
		}

		/**
		 * Debug output and/or logging in a variety of formats
		 */
		protected function dbug( $a = null, $b = null, $c = null, $d = null, $e = null, $f = null ) {
			if ( $a ) { var_dump( $a ); }
			if ( $b ) { var_dump( $b ); }
			if ( $c ) { var_dump( $c ); }
			if ( $d ) { var_dump( $d ); }
			if ( $e ) { var_dump( $e ); }
			if ( $f ) { var_dump( $f ); }
		}

	}

	// Add the command to WP_CLI
	WP_CLI::add_command( 'atlas', 'Atlas_CLI_Command', array( 
		'before_invoke' => function() {
			require_once( 'atlas-date-functions.php' );
			require_once( 'class-clover.php' );
			require_once( 'vendor/autoload.php' );
		}
	));
}
