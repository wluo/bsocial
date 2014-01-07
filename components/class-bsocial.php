<?php

class bSocial
{
	public $twitter = NULL;
	public $linkedin = NULL;
	public $facebook = NULL;

	public $linkedin_user_info = NULL;
	public $linkedin_user_stream = NULL;
	public $facebook_user_info = NULL;
	public $facebook_user_stream = NULL;

	public function __construct()
	{
		// activate the sub-components
		$this->activate();			

		add_action( 'wp_ajax_show_cron', array( $this, 'show_cron' ) );
		add_action( 'delete_comment', array( $this, 'comment_id_by_meta_delete_cache' ) );
	}

	public function activate()
	{
		// the admin settings page
		if ( is_admin() )
		{
			$this->admin();			
		}

		// get options with defaults
		$this->options = apply_filters( 'go_config', wp_parse_args( (array) get_option( 'bsocial-options' ), array( 
			'open-graph' => 1,
			'featured-comments' => 1,
			'featured-comments-commentdate' => 1,
			'featured-comments-waterfall' => 1,
			'twitter-api' => 1,
			'twitter-comments' => 1,
			'twitter-app_id' => '',
			'facebook-api' => 1,
			'facebook-add_button' => 1,
			'facebook-comments' => 0,
			'facebook-admins' => '',
			'facebook-app_id' => '',
			'facebook-secret' => '',
		)), 'bsocial' );

		// Better describe your content to social sites
		if ( $this->options['open-graph'] )
		{
			require_once __DIR__ .'/open-graph.php';
		}

		// Feature your comments
		if ( $this->options['featured-comments'] )
		{
			require_once __DIR__ .'/class-bsocial-featuredcomments.php';
			$featured_comments = new bSocial_FeaturedComments;
			$featured_comments->use_comment_date = $this->options['featured-comments-commentdate'];
			$featured_comments->add_to_waterfall = $this->options['featured-comments-waterfall'];
		}
		
		// Twitter components
		if ( $this->options['twitter-api'] )
		{
			require_once __DIR__ .'/twitter-api.php';
			$twitter_api = new bSocial_TwitterApi;
			$twitter_api->app_id = $this->options['twitter-app_id'];
		
			if ( $this->options['twitter-card_site'] )
			{
				$twitter_api->card_site = $this->options['twitter-card_site'];
			}
		
			if ( $this->options['twitter-comments'] )
			{
				require_once __DIR__ .'/twitter-comments.php';
			}
		}	
		
		// Facebook components
		if ( $this->options['facebook-api'] && $this->options['facebook-app_id'] )
		{
			require_once __DIR__ .'/class-bsocial-facebook-api.php';
			$facebook_api = new bSocial_FacebookApi;
			$facebook_api->options->add_like_button = $this->options['facebook-add_button'];
			$facebook_api->admins = $this->options['facebook-admins'];
			$facebook_api->app_id = $this->options['facebook-app_id'];
		
			require_once __DIR__ .'/widgets-facebook.php';
		
			if( $this->options['facebook-comments'] && $this->options['facebook-secret'])
			{
				require_once __DIR__ .'/class-bsocial-facebook-comments.php';
				$facebook_comments = new bSocial_FacebookComments;
				$facebook_comments->app_id = $this->options['facebook-app_id'];
				$facebook_comments->app_secret = $this->options['facebook-secret'];
			}
		}
	}

	public function admin()
	{
		if ( ! isset( $this->admin ))
		{
			require_once __DIR__ . '/class-bsocial-admin.php';
			$this->admin = new bSocial_Admin;
		}

		return $this->admin;
	}

	public function tests()
	{
		if ( ! isset( $this->tests ) )
		{
			$this->tests = array();

			require_once __DIR__ . '/class-bsocial-twitter-test.php';
			$this->tests[] = new bSocialTwitter_Test();

			require_once __DIR__ . '/class-bsocial-linkedin-test.php';
			$this->tests[] = new bSocialLinkedIn_Test();

			require_once __DIR__ . '/class-bsocial-facebook-test.php';
			$this->tests[] = new bSocialFacebook_Test();
		}//END if

		return $this->tests;
	}//END tests

	public function url_to_blogid( $url )
	{
		if( ! is_multisite() )
		{
			return FALSE;			
		}

		global $wpdb, $base;

		$url = parse_url( $url );
		if ( is_subdomain_install() )
		{
			return get_blog_id_from_url( $url['host'] , '/' );
		}
		elseif( ! empty( $url['path'] ) )
		{
			// get the likely blog path
			$path = explode( '/' , ltrim( substr( $url['path'] , strlen( $base )) , '/' ));
			$path = empty( $path[0] ) ? '/' : '/'. $path[0] .'/';
			// get all blog paths for this domain
			if( ! $paths = wp_cache_get( $url['host'] , 'paths-for-domain' ))
			{
				$paths = $wpdb->get_col( "SELECT path FROM $wpdb->blogs WHERE domain = '". $wpdb->escape( $url['host'] ) ."' /* url_to_blogid */" );
				wp_cache_set( $url['host'] , $paths , 'paths-for-domain' , 3607 ); // cache it for an hour
			}
			// chech if the given path is among the known paths
			// allows us to differentiate between paths of the main blog and those of sub-blogs
			$path = in_array( $path , $paths ) ? $path : '/';
			return get_blog_id_from_url( $url['host'] , $path );
		}

		// cry uncle, return 1
		return 1;
	}

	public function find_urls( $text )
	{
		// nice regex thanks to John Gruber http://daringfireball.net/2010/07/improved_regex_for_matching_urls
		preg_match_all( '#(?i)\b((?:https?://|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?гхрсту]))#', $text, $urls );

		return $urls[0];
	}

	public function follow_url( $location , $verbose = FALSE , $refresh = FALSE )
	{
		if ( $refresh || ( ! $trail = wp_cache_get( (string) $location , 'follow_url' ) ) )
		{
			$headers = get_headers( $location );
			$trail = array();
			$destination = $location;
			foreach( (array) $headers as $header )
			{
				if ( 0 === stripos( $header , 'HTTP' ))
				{
					preg_match( '/ [1-5][0-9][0-9] /' , $header , $matches );
					$trail[] = array( 'location' => $destination , 'response' => trim( $matches[0] ));
				}

				if( 0 === stripos( $header , 'Location' ))
				{
					$destination = array_pop( $this->find_urls( $header ));
				}
			}

			wp_cache_set( (string) $location , $trail, 'follow_url' , 3607); // cache for an hour
		}

		if( $verbose )
		{
			return $trail;
		}
		else
		{
			return $trail[ count( $trail ) - 1 ]['location'];
		}
	}

	public function comment_id_by_meta( $metavalue , $metakey )
	{
		global $wpdb;

		if ( ! $comment_id = wp_cache_get( (string) $metakey .':'. (string) $metavalue , 'comment_id_by_meta' ) )
		{
			$comment_id = $wpdb->get_var( $wpdb->prepare( 'SELECT comment_id FROM ' . $wpdb->commentmeta . ' WHERE meta_key = %s AND meta_value = %s',  $metakey, $metavalue ));
			wp_cache_set( (string) $metakey .':'. (string) $metavalue , $comment_id , 'comment_id_by_meta' );
		}

		return $comment_id; 
	}

	public function comment_id_by_meta_update_cache( $comment_id , $metavalue , $metakey )
	{
		if ( 0 < $comment_id )
		{
			return;
		}

		if ( ( ! $metavalue ) || ( ! $metakey ))
		{
			return;
		}

		wp_cache_set( (string) $metakey .':'. (string) $metavalue , (int) $comment_id , 'comment_id_by_meta' );
	}

	public function comment_id_by_meta_delete_cache( $comment_id )
	{
		foreach ( (array) get_metadata( 'comment' , $comment_id ) as $metakey => $metavalues )
		{
			foreach( $metavalues as $metavalue )
			{
				wp_cache_delete( (string) $metakey .':'. (string) $metavalue , 'comment_id_by_meta' );
			}
		}
	}

	public function json_int_to_string( $string )
	{
		//32-bit PHP doesn't play nicely with the large ints FB returns, so we
		//encapsulate large ints in double-quotes to force them to be strings
		//http://stackoverflow.com/questions/2907806/handling-big-user-ids-returned-by-fql-in-php
		return preg_replace( '/:(\d+)/' , ':"${1}"' , $string );
	}

	/**
	 * return our handle to our twitter client object
	 */
	public function twitter()
	{
		if ( ! $this->twitter )
		{
			if ( ! class_exists( 'bSocial_Twitter' ) )
			{
				require __DIR__ .'/class-bsocial-twitter.php';
			}
			$this->twitter = new bSocial_Twitter();
		}
		return $this->twitter;
	}//END twitter

	public function linkedin_user_info()
	{
		if( ! $this->linkedin_user_info )
		{
			require_once __DIR__ .'/class-bsocial-linkedin-user-info.php';
			$this->linkedin_user_info = new bSocial_LinkedIn_User_Info;
		}
		return $this->linkedin_user_info;
	}//END linkedin_user_info

	public function linkedin_user_stream()
	{
		if( ! $this->linkedin_user_stream )
		{
			require_once __DIR__ .'/class-bsocial-linkedin-user-stream.php';
			$this->linkedin_user_stream = new bSocial_LinkedIn_User_Stream;
		}
		return $this->linkedin_user_stream;
	}//END linkedin_user_stream

	public function facebook_user_info()
	{
		if( ! $this->facebook_user_info )
		{
			require_once __DIR__ .'/class-bsocial-facebook-user-info.php';
			$this->facebook_user_info = new bSocial_Facebook_User_Info;
		}
		return $this->facebook_user_info;
	}//END facebook_user_info

	public function facebook_user_stream()
	{
		if( ! $this->facebook_user_stream )
		{
			require_once __DIR__ .'/class-bsocial-facebook-user-stream.php';
			$this->facebook_user_stream = new bSocial_Facebook_User_Stream;
		}
		return $this->facebook_user_stream;
	}//END facebook_user_stream

	// Show cron array for debugging
	public function show_cron()
	{
		if (current_user_can('manage_options'))
		{
			echo '<pre>' .  print_r(_get_cron_array(), true) . '</pre>';  
		};
		exit; 
	}
}//END class

function bsocial()
{
	global $bsocial;

	if( ! $bsocial )
	{
		$bsocial = new bSocial();
	}

	return $bsocial;
}//END bsocial