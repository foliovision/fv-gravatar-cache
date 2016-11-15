<?php
/*
Plugin Name: FV Gravatar Cache
Plugin URI: http://foliovision.com/seo-tools/wordpress/plugins/fv-gravatar-cache
Version: 0.4
Description: Speeds up your website by making sure the gravatars are stored on your website and not loading from the gravatar server.
Author: Foliovision
Author URI: http://foliovision.com
*/

Class FV_Gravatar_Cache {
  // increase this number if you want to purge the truncate gravatars table:
  private $db_version = '0.4';

  var $log;
  
  /*
  Init all the hooks
  */
  function __construct() {
    //  admin stuff
    add_action( 'admin_init', array( &$this, 'CheckVersion' ) );
    add_action( 'admin_init', array( &$this, 'OptionsHead' ) );
    add_action( 'admin_menu', array( &$this, 'OptionsPage') );
    add_action('wp_footer', array( &$this, 'IsAdmin' ),1 );
    add_filter('plugin_action_links', array( &$this, 'plugin_action_links'), 10, 2);
    //  display warning if no options are set
    if( !get_option( 'fv_gravatar_cache') ) {
      add_option('fv_gravatar_cache_nag','1');
    }
    add_action( 'admin_notices', array( $this, 'AdminNotices') );

    //  change gravatar HTML if cache is configured
    if( get_option( 'fv_gravatar_cache') ) {
      add_filter( 'get_avatar', array( &$this, 'GetAvatar'), 10, 2); 
    }
    //  prepare the gravatar cache data prior to displaying comments
    add_filter( 'comments_array', array( &$this, 'CommentsArray' ) );
    //  refresh gravatars also on comment submit and save
    add_action('comment_post', array(&$this,'NewComment'), 100000, 1);
    add_action('edit_comment', array(&$this,'NewComment'), 100000, 1);

    add_action( 'wp_ajax_load_gravatar_list', array( $this, 'load_gravatar_list' ) );
  }
  
  
  /**
   * Show warning, if no options are set
   */
  function AdminNotices() {
    if( get_option( 'fv_gravatar_cache_nag') ) {
      echo '<div class="updated fade"><p>FV Gravatar Cache needs to be configured before operational. Please configure it <a href="'.get_bloginfo( 'wpurl' ).'/wp-admin/options-general.php?page=fv-gravatar-cache">here</a>.</p></div>'; 
    }    
  }

  
  function IsAdmin(){
    $this->dont_cache = 1;
  }
  
  
  /**
   * Get the data from gravatar cache table
   *
   * @param array $comments Array of all the displayed comments.
   * @return array Associative array email => URL
   */
  function CacheDB( $comments = NULL, $limit = 1000, $start = 0 ) {
    global $wpdb;
    //  if array of displayed comments is present, just get the desired gravatars
    if( $comments !== NULL && count( $comments ) > 0 ) {
      //  put all the emails into string
      $all_emails = array();
      foreach( $comments AS $comment ) {
        $all_emails[] = '\''. strtolower( $comment->comment_author_email ).'\'';
      }
      $all_emails = array_unique( $all_emails );
      $all_emails_string = implode( ',', $all_emails );
      //  get data
      $fv_gravatars = $wpdb->get_results( "SELECT email, url, time FROM `{$wpdb->prefix}gravatars` WHERE email IN ({$all_emails_string}) " );
    }
    //  or get the whole cache data
    else {
      $fv_gravatars = $wpdb->get_results( "SELECT email, url, time FROM `{$wpdb->prefix}gravatars` LIMIT {$limit} OFFSET {$start}" );
    }
    //  make it associative array
    foreach( $fv_gravatars AS $key => $value ) {
      $email = strtolower($value->email);
      $fv_gravatars[ $email ] = array( 'url' => $value->url, 'time' => $value->time );
      unset($fv_gravatars[$key]);
    }
    return $fv_gravatars;
  }
  
  
  /**
   * Check cache directory
   */
  function CheckWritable() {
    $options = get_option('fv_gravatar_cache');
    if( isset($options) ) {
      $path = $this->GetCachePath();
      return is_writable( $path );
    }
  }
  
  
  /**
   * Close log
   *
   */
  function CloseLog( ) {
    $options = get_option('fv_gravatar_cache');
    if( isset($options['debug']) && $options['debug'] == true ) {
      @fclose( $this->log );
    }
  }
  
  
  /**
   * Caches a single gravatar based on known email address and size. Also updates the db.
   *
   * @param string $email Email address.
   * @param int $size Desired gravatar size
   * @return string URL of cached gravatar
  */
  function Cron( $email, $size = '96' ) {
    global $wpdb;
  	if ( ! get_option('show_avatars') )
  		return false;
  	if ( !is_numeric($size) )
  		$size = '96';
    if( false === ( $options = get_option('fv_gravatar_cache') ) ){
      return false;
    }
    
    $email  = strtolower( $email );
    $time   = time();
    $last_update = $wpdb->get_var( "SELECT `time` FROM `{$wpdb->prefix}gravatars` WHERE email = '{$email}'" );
    if( $time < $last_update + 24*3600 ) {
      return false;
    }

    //TODO add sizes as option in administration
    $aGravatars = array();
    $aGravatars[$size] = $this->Cache( $email, '', $size );
    if( $options['retina'] ){
      //download retina image
      $aGravatars[($size*2)] = $this->Cache( $email, '', ($size*2) );
    }
    $gravatars_serialized = serialize($aGravatars);
    
    if( !$last_update ) { // not in cache
      $wpdb->query( "INSERT INTO `{$wpdb->prefix}gravatars` (email,time,url) VALUES ( '{$email}', '{$time}', '{$gravatars_serialized}' ) " );
      $this->Log( 'INSERT, '.$gravatars_serialized .', '.$size.', '.$email.', '.date(DATE_RFC822).' Error: '.var_export( $wpdb->last_error, true )."\r\n" );
    }
    else {
      $wpdb->query( "UPDATE `{$wpdb->prefix}gravatars` SET url = '{$gravatars_serialized}', time = '{$time}' WHERE email = '{$email}' " );
      $this->Log( 'UPDATE, '.$gravatars_serialized .', '.$size.', '.$email.', '.date(DATE_RFC822)."\r\n" );
    }
    
  	return $aGravatars[$size];  //  this needs to change to just picture
  }
  
  
  /**
   * Check if is plugin after update, if so, empty cache
   */
  function CheckVersion() {
    global $wpdb;
    
    $options = get_option('fv_gravatar_cache');
    //after update?
    if( !isset($options['version']) || ( isset($options['version']) && $options['version'] != $this->db_version ) ){
      
      $wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}gravatars` " );
      update_option( 'fv_gravatar_cache_offset', 0 );

      $options['version'] = $this->db_version ;
      update_option( 'fv_gravatar_cache', $options);
      }
  }
  
  /**
   * Replace the gravatar HTML if gravatar is cached
   *
   * @return string New HTML
   */
  function GetAvatar( $image, $id_or_email ) {
    global $comment;

    if( is_admin() ){
      return $image;
    }
    
    if( false === ( $options = get_option('fv_gravatar_cache') ) ){
      return $image;
    }
    
    if( isset($this->dont_cache) && $this->dont_cache == 1) {
      return $image;
    }
    
    //  get the cached data
    $gravatars  = wp_cache_get('fv_gravatars_set', 'fv_gravatars');
    $email      = strtolower( $comment->comment_author_email );
    
    //  check out the cache. If the entry is not found, then you will have to insert it, no update.
    if( isset( $gravatars ) && ( !isset( $gravatars[$email] ) || $gravatars[$email]['url'] == '' ) ) {
      return $image;  //  just display the remote image, don't download the gravatar
    }
    
    //  match the current gravatar URL
    if( !preg_match( '/src=\'(.*?)\'/', $image, $url ) ){
      return $image;
    }
    
    $gravatar_data = maybe_unserialize( $gravatars[$email]['url'] );
    
    if( is_array($gravatar_data) ){
      $size = $options['size'];
      
      //replace original size image
      if( isset($gravatar_data[$size]) ){
        $image = str_replace( $url[1], $gravatar_data[$size], $image );
      }
      //replace retina size image
      if( isset($gravatar_data[($size*2)]) && preg_match( '/srcset=\'(.*?)\'/', $image, $retina ) ){
        $image = str_replace( $retina[1], $gravatar_data[($size*2)], $image );
      }
    }
    else{
      // we have only one url saved in database
      $image = str_replace( $url[1], $gravatar_data, $image );
    }

    return $image;
  }
  
  
  /**
   * Used to open URLs. Have to do some checks because of varying server settings
   *
   * @param string $url
   * @return string
   */
  function GetFromURL($url) {
  	// Use file_get_contents
  	if (ini_get('allow_url_fopen') && function_exists('file_get_contents')) {
  		return @file_get_contents($url);
  	}
  	// Use fopen
  	if (ini_get('allow_url_fopen') && !function_exists('file_get_contents')) {
  		if (false === $fh = fopen($url, 'rb', false)) {
  			user_error('file_get_contents() failed to open stream: No such file or directory', E_USER_WARNING);
  			return false;
  		}
  		//clearstatcache();
  		if ($fsize = @filesize($url)) {
  			$data = fread($fh, $fsize);
  		} else {
  			$data = '';
  			while (!feof($fh)) {
  				$data .= fread($fh, 8192);
  			}
  		}
  		fclose($fh);
  		return $data;
  	}
  	// Use cURL
  	if (function_exists('curl_init')) {
  		$c = curl_init($url);
  		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
  		curl_setopt($c, CURLOPT_TIMEOUT, 15);
  		$data = @curl_exec($c);
  		curl_close($c);
  		return $data;
  	}
  	return false;
  }
  
  
  function GetCache( $url = false ) {
    // Custom upload path disabled
    /*$options = get_option('fv_gravatar_cache');
    
    if(isset($options['URL'])){
      $path = $options['URL'];
    }
    
    if( $path == '' || !isset($path) ) {
      $path = WP_PLUGIN_DIR.'/'.dirname( plugin_basename( __FILE__ ) ).'/images';
    }*/


    if ( ! WP_Filesystem(true) ) {
      return false;
    }

    global $wp_filesystem;

    $aUpload    = wp_upload_dir();
    $upload_dir = '/fv-gravatar-cache';

    if( !$wp_filesystem->exists($aUpload['basedir'] . $upload_dir . '/') && !$wp_filesystem->mkdir($aUpload['basedir'] . $upload_dir . '/') ) {
      return false;
    }

    if( $url ) {
      $cache = $aUpload['baseurl']. $upload_dir . '/';
      $cache = str_replace( array( 'http:', 'https:' ), '', $cache );
    }
    else {
      $cache = $aUpload['basedir']. $upload_dir . '/';
    }

    return $cache;
  }


  /**
   * Get the cache server path
   *
   * @return string
   */
  function GetCachePath() {
    return $this->GetCache( false );
  }
  
  
  /**
   * Get the cache URL
   *
   * @return string
   */
  function GetCacheURL() {
    return $this->GetCache( true );
  }
  
  
  /**
   * Runs before comments are displayed and caches an array with all the email addresses and gravatars in it.
   *
   * @param array $comments Array of all the displayed comments.
   * @return array Unchanged array of all the displayed comments.
   */
  function CommentsArray( $comments ) {
    $fv_gravatars = $this->CacheDB( $comments );
    $myexpire = 120;
    //  use wp cache to store the data
    wp_cache_set('fv_gravatars_set', $fv_gravatars, 'fv_gravatars', $myexpire);
    return $comments;
  }
  
  
  /**
   * Download a single gravatar. Provide email address and alternativelly also gravatar URL
   *
   * @param string $email User email.
   * @param string $url Gravatar URL.
   * @return string Gravatar URL.
   */
  function Cache( $email= '', $url = '', $size = false ) {
    $options = get_option('fv_gravatar_cache');
    
    if( !$size ){
      $size = $options['size'];
    }
    
    if( !$size ) {
      return;
    }
    
    //  if we don't have the gravatar url, we must create it
    if( $url == '' ) {
      if ( is_ssl() )
    		$host = 'https://secure.gravatar.com';
    	else
    		$host = 'http://www.gravatar.com';
      //  get default gravatar; this might not work with autogenerated gravatars
      if( $email == 'default' ) {
    		$avatar_default = get_option('avatar_default');
    		if ( empty($avatar_default) )
    			$default = 'mystery';
    		else
    			$default = $avatar_default;
      	if ( 'mystery' == $default )
      		$default = "$host/avatar/ad516503a11cd5ca435acc9bb6523536?s={$size}"; // ad516503a11cd5ca435acc9bb6523536 == md5('unknown@gravatar.com')
      	elseif ( 'blank' == $default ) {
      		$default = includes_url('images/blank.gif');
      		return $default;
      	}
      	/*elseif ( !empty($email) && 'gravatar_default' == $default )
      		$default = '';*/
      	elseif ( 'gravatar_default' == $default ) {
      		$default = "$host/avatar/?s={$size}";
      	}
      	elseif ( empty($email) )
      		$default = "$host/avatar/?d=$default&amp;s={$size}";
      	elseif ( strpos($default, 'http://') === 0 )
      		$default = add_query_arg( 's', $size, $default );
      	$out = $default;
      	$filename = 'default'.$size;
      }	
      //  get gravatar or report 404
    	else {
      	$out = "$host/avatar/";
      	$out .= md5( strtolower( $email ) );
    	  $out .= '?d=404'; //  this must be the first parameter in order to work with 404 instead of default gravatars
    	  $out .= '&s='.$size;
    	  $filename = md5( strtolower( $email ) ).'x'.$size;
    	}
    	$rating = get_option('avatar_rating');
    	if ( !empty( $rating ) ) {
    		$out .= "&amp;r={$rating}";
    	}
    }
    //  if we know the URL already
    else {
      $out = $url;
      $filename = md5( strtolower( $email ) ).'x'.$size;
    }
  	/*
  	Download part
  	*/
  	//set_time_limit(2);
  	// if directory is writable
  	if( $this->CheckWritable() ) {
      //  check if gravatar exists
      $headers = @get_headers( $out );
      if( stripos( $headers[0], '404' ) !== FALSE ) {
        return $options['default'];
      }
  	  $gravatar = $this->GetFromURL( $out );
  	  ///  0.3.1 fix
  	  if( stripos( $gravatar, '404 File does not exist' ) !== FALSE || stripos( $gravatar, '404 Not Found' ) !== FALSE ) {
  	      return $options['default'];
  	  }
  	  ///  	  
      $myURL = $this->GetCacheURL().$filename.'.png';
      $myFile = $this->GetCachePath().$filename.'.png';
      
      $fh = fopen( $myFile, 'w' );
      if( $fh ) {
        fwrite( $fh, $gravatar );
        fclose( $fh );
      }
    }     
    
    return $myURL;
  }
  
  
  /**
   * Write something into log
   *
   * @param string $string String to be recorded.
   */
  function Log( $string ) {
    $options = get_option('fv_gravatar_cache');
    if( $options['debug'] == true ) {
      if( $this->log ) {
        @fwrite( $this->log, $string, strlen( $string ) );
      }
      //echo $string.'<br />';
    }
  }
  
  
  /**
   * Open log
   *
   */
  function OpenLog( ) {
    $options = get_option('fv_gravatar_cache');
    if( $options['debug'] == true ) {
      $this->log = @fopen( $this->GetCachePath().'log-'.md5( AUTH_SALT ).'.txt', "w+" );
    }
  }
  
  
  /**
   * Cache gravatar of new comment
   *
   */
  function NewComment( $comment_ID ) {
		$comment = get_comment($comment_ID);	
		if (!$comment) return;
		//  don't cache what goes into trash or spam
		if( $comment->comment_approved == 'trash' || $comment->comment_approved == 'spam' ) return;
		/*$this->OpenLog();
    $this->Log( "\r\n"."\r\nNew Comment submission: ".date(DATE_RFC822) );
    $this->Log( var_export( $comment, true )."\r\n"."\r\n" );
    $this->CloseLog();*/
		// remove get_avatar filter
		remove_filter('get_avatar', array(&$this, 'GetAvatar'), 10, 2); 
		// cache gravatar
		$options = get_option( 'fv_gravatar_cache');
		return $this->Cron( $comment->comment_author_email, $options['size'] );	
  }
  
  
  /*
  Save settings
  */
  function OptionsHead() {
      if(stripos($_SERVER['REQUEST_URI'],'/options-general.php?page=fv-gravatar-cache')!==FALSE) {
          $options = get_option('fv_gravatar_cache');
          if(isset($_POST['fv_gravatar_cache_save'])) {              
              check_ajax_referer( 'fv_gravatar_cache', 'fv_gravatar_cache' );
              delete_option('fv_gravatar_cache_nag');
              //$options['URL'] = $_POST['URL'];
              $options['size'] = $_POST['size'];
              if( isset( $_POST['retina'] ) ) {
                $options['retina'] = true;
              }
              else{
                $options['retina'] = false;
              }
              if( isset( $_POST['debug'] ) ) {
                $options['debug'] = true;
              }
              else {
                $options['debug'] = false;
              }
              if( isset( $_POST['cron'] ) ) {
                $options['cron'] = true;
              }
              else {
                $options['cron'] = false;
              }
              update_option('fv_gravatar_cache', $options); 
              $options['default'] = $this->Cache( 'default', '' );
              update_option('fv_gravatar_cache', $options);  
          }elseif(isset($_POST['fv_gravatar_cache_clear'])) {
              check_ajax_referer( 'fv_gravatar_cache', 'fv_gravatar_cache' );
              global $wpdb;
              $wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}gravatars` " );
              update_option( 'fv_gravatar_cache_offset', 0 );
          }elseif(isset($_POST['fv_gravatar_cache_refresh'])) {
              check_ajax_referer( 'fv_gravatar_cache', 'fv_gravatar_cache' );
              fv_gravatar_cache_cron_run();
          }      
      }
  }
  
  
  /**
   * Display options page
   *
   */
  function OptionsManage()
  {
    /*  Display */
  ?>
  <div class="wrap">
      <div id="icon-tools" class="icon32"><br /></div>
          <h2>FV Gravatar Cache</h2>
      <!-- --> 
      <?php
      if(!function_exists('curl_init')) {
      	echo '<div class="error fade"><p>Please make sure Curl is installed. Talk to your host support about this.</p></div>';
      }
      if( !$this->CheckWritable() ) {
        echo '<div class="error fade"><p>Warning: Current Cache directory is not writable. Gravatar Cache will not work.</p></div>'; 
      }
      ?>
      <!-- -->
      <?php
      //  let's guess the avatar size - sets $guessed_size
      $filenames = array();
      $filenames[] = get_template_directory().'/functions.php';
      $filenames[] = get_template_directory().'/single.php';
      $filenames[] = get_template_directory().'/comments.php';
      foreach( $filenames AS $filename ) {
        $fp = @fopen( $filename, "r" );
        if( $fp ) {
          $file_content = @fread($fp, filesize($filename));
          if( $file_content ) {
            preg_match( '/avatar_size=(\d*)/', $file_content, $size );
            preg_match( '/get_avatar\(\D*?(\d*)\D*?\)/', $file_content, $size );
            if( isset($size[1] ) ){
              if( is_numeric( $size[1]  ) ) {
                $guessed_size = $size[1];
                break;
              }
            }
          }
        }
      }
      ?>
      <?php
      //  debug output of options
      $options = get_option('fv_gravatar_cache'); //var_dump( $options );
      $cron_offset = get_option( 'fv_gravatar_cache_offset' );
      ?>
      <?php
      global $wpdb;
      $count = $wpdb->get_var( "SELECT count( email ) FROM `{$wpdb->prefix}gravatars` " );
          
      ?>
      <form id="gravatarcache" action="" method="post">
        <table class="form-table">
          <tbody>
            <tr valigin="top">
              <th scope="row">Cache directory:</th><td><?php echo $this->GetCachePath(); ?></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Cache directory URL:</th><td><?php echo $this->GetCacheURL(); ?></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Default Gravatar:<br /><small>(Hit "Save changes" button to store locally selected "Default Avatar" from Settings -> Discussion. If you will change WordPress "Default Avatar" in future, you need to update it here as well.)</small></th><td><img src="<?php echo $options['default']; ?>" /></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Cache information:</th><td><?php echo $count; ?> items in cache (<a href="#" onclick="fv_gravatar_cache_load_list(0)">show</a>)</td>
            </tr>
            <tr valigin="top">
              <td colspan="2">
              <ul id="fv-gravatar-cache-list" style="display: none; ">

              </ul>
              </td>
            </tr>
            <tr valigin="top">
              <th scope="row">Current time:</th><td><?php echo date(DATE_RFC822); ?></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Last Cron run:</th><td><?php if( isset($options['last_run']) && !empty($options['last_run']) ) echo $options['last_run']; ?></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Current Cron offset:</th><td><?php if( isset($cron_offset) && !empty($cron_offset) ) echo $cron_offset; ?></td>
            </tr>
            <tr valigin="top">
              <th scope="row">&nbsp;</th><td>&nbsp;</td>
            </tr>

            <?php /*
            <tr valigin="top">
              <th scope="row">Custom Cache directory URL:</th><td><input name="URL" type="text" value="<?php if(isset($options['URL'])) echo $options['URL']; ?>" size="50" /> <small>(Leave empty for PLUGIN_DIR/images)</small></td>
            </tr>
            */ ?>
            <tr valigin="top">
              <th scope="row">Gravatar size:</th><td><input name="size" type="text" value="<?php if( isset($options['size']) ) echo $options['size']; else echo '96';  ?>" size="8" />
              <?php if(isset( $guessed_size ) ) {
                echo '<small>(<acronym title="You can get this value by checking out image properties for any of the gravatars in your browser">Guessed Gravatar size: '.$guessed_size.'</acronyme>)</small>';
              }
              ?></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Retina images:</th><td><input name="retina" type="checkbox" <?php if( isset( $options['retina'] ) && $options['retina'] ) echo 'checked="yes" '; ?> /> <small>(Download retina images for gravatars)</small></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Daily cron:</th><td><input name="cron" type="checkbox" <?php if( isset( $options['cron'] ) && $options['cron'] ) echo 'checked="yes" '; ?> /> <small>(Will keep refreshing gravatars during day in smaller chunks)</small></td>
            </tr>
            <tr valigin="top">
              <th scope="row">Debug mode:</th><td><input name="debug" type="checkbox" <?php if( $options['debug'] == true ) echo 'checked="yes" '; ?> /> <small>(check <a target="_blank" href="<?php echo $this->GetCacheURL().'log-'.md5( AUTH_SALT ).'.txt'; ?>">log.txt</a> file in Cache directory)</small></td>
            </tr>
          </tbody>
        </table>
        <p class="submit">
          <?php wp_nonce_field( 'fv_gravatar_cache', 'fv_gravatar_cache', false ); ?>
          <input class="button-primary" type="submit" name="fv_gravatar_cache_save" value="Save changes"/>
          <input class="button" type="submit" name="fv_gravatar_cache_clear" value="Empty Cache"/>
          <input class="button" type="submit" name="fv_gravatar_cache_refresh" value="Run Cron Now"/>
        </p>
      </form>
  </div>

  <style>
  .gravatar_list_paging {
    margin-top: 20px;
    display: table;
  }

  .gravatar_list_paging li {
    display: inline-block;
  }

  .gravatar_list_paging li a {
    padding: 10px;
    border: solid 1px #DDDDDD;
    vertical-align: middle;
    text-decoration: none;
    color: #333333;
  }

  .gravatar_list_paging li a.active {
    font-weight: bold;
  }

  </style>

  <script type="text/javascript">
  function fv_gravatar_cache_load_list( page = 0 ) {

    if( page == 0 ) {
      jQuery('#fv-gravatar-cache-list').show();
    }

    var data = {
      'action': 'load_gravatar_list',
      'page': page
    };

    jQuery.post(ajaxurl, data, function(response) {
      jQuery( "#fv-gravatar-cache-list" ).html( response );
    });

    return false;
  }

  </script>
  <?php
  }
  /*
  Settings page
  */
  function OptionsPage()
  {
  	if (function_exists('add_options_page'))
  	{
  		add_options_page('FV Gravatar Cache', 'FV Gravatar Cache', 'edit_pages', 'fv-gravatar-cache', array( &$this, 'OptionsManage' ) );
  	}
  }
  function plugin_action_links($links, $file) {
  	$plugin_file = basename(__FILE__);
  	if (basename($file) == $plugin_file) {
  		$settings_link = '<a href="options-general.php?page='.str_replace( '.php', '', $plugin_file ).'">Settings</a>';
  		array_unshift($links, $settings_link);
  	}
  	return $links;
  }
  /*
  Cron starter
  */
  function RunCron() {
    $this->OpenLog();
    $this->Log( date(DATE_RFC822) );
    $this->CloseLog();
  }


  function load_gravatar_list() {
    global $wpdb;
    $options = get_option('fv_gravatar_cache');

    $total  = $wpdb->get_var( "SELECT count( email ) FROM `{$wpdb->prefix}gravatars` " );
    $page   = intval( $_POST['page'] );
    $limit  = 20;
    $start  = $limit*$page;

    $cache = $this->CacheDB( null, $limit, $start );
    foreach( $cache AS $cache_key => $cache_item ) {
      $cache_item_data = maybe_unserialize( $cache_item['url'] );
      if( is_array($cache_item_data) ){
        if( !isset( $cache_item_data[$options['size']] ) ){
          continue;
        }
        $item_url = $cache_item_data[$options['size']];
      }
      else{
        $item_url = $cache_item_data;
      }
      echo '<li><img src="'.$item_url.'" width="16" height="16" /> '.$cache_key.'</li>';
    }

    // build paging
    echo '<li>';
      echo '<ul class="gravatar_list_paging">';
      if( $page > 0 ) {
        echo '<li><a href="#" onclick="fv_gravatar_cache_load_list(0)">First</a></li>';
        echo '<li><a href="#" onclick="fv_gravatar_cache_load_list('.($page-1).')">Previous</a></li>';
      }

      for( $i = $page - 2; $i < $page + 3; $i++ ) {
        if( $i < 0 || $i > floor($total/$limit) ) {
          continue;
        }

        if( $page == $i ) {
          echo '<li><a class="active" href="#" onclick="return false">'.($i+1).'</a></li>'; // user friendly paging 0 => 1
        }
        else {
          echo '<li><a href="#" onclick="fv_gravatar_cache_load_list('.$i.')">'.($i+1).'</a></li>'; // user friendly paging 0 => 1
        }
        
      }

      if( $start+$limit < $total ) {
        echo '<li><a href="#" onclick="fv_gravatar_cache_load_list('.( $page+1 ).')">Next</a></li>';
        echo '<li><a href="#" onclick="fv_gravatar_cache_load_list('.floor( $total/$limit ).')">Last</a></li>';
      }

      echo '</ul>';
    echo '</li>';

    die();
  }
}


/*
Create the cache table
*/
function fv_gravatar_cache_activation() {
    global $wpdb;
    $wpdb->query ("CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}gravatars` (
      `id` int(11) unsigned NOT NULL auto_increment,
      `email` VARCHAR(64) NOT NULL UNIQUE,
      `url` mediumtext NOT NULL,
      `time` int NOT NULL,      
      PRIMARY KEY  (`id`)
    )");
    
    $wpdb->query ("ALTER TABLE `{$wpdb->prefix}gravatars` ADD `time` int");
}
register_activation_hook( __FILE__, 'fv_gravatar_cache_activation' );


function fv_gravatar_cache_deactivation() {
  wp_clear_scheduled_hook('fv_gravatar_cache_cron');
}
register_deactivation_hook(__FILE__, 'fv_gravatar_cache_deactivation');


/*
 * Cron stuff
*/
function fv_gravatar_cache_cron_schedules( $schedules )
{
  $schedules['5minutes'] = array(
    'interval' => 300,
    'display' => __('Every 5 minutes')
  );
  return $schedules;
}
add_filter('cron_schedules', 'fv_gravatar_cache_cron_schedules'); 


if (is_admin()) {
  if ( !wp_next_scheduled( 'fv_gravatar_cache_cron' ) ) {
    wp_schedule_event( time(), '5minutes', 'fv_gravatar_cache_cron' );
  }
}
add_action( 'fv_gravatar_cache_cron', 'fv_gravatar_cache_cron_run' );


/**
  * Run the cron job.
  *
  */
function fv_gravatar_cache_cron_run( ) {
  global $FV_Gravatar_Cache, $wpdb;
  $options = get_option( 'fv_gravatar_cache');
  if( !$options ) {
    return;
  }
  //  run only if cron is turned on or if it's a forced refresh
  if( !isset( $_POST['fv_gravatar_cache_refresh'] ) &&  $options['cron'] == false ) {
    return;
  }
  //  make sure offset is not outsite the scope
  $count = $wpdb->get_var( "SELECT COUNT( DISTINCT comment_author_email ) FROM $wpdb->comments WHERE comment_author_email != '' AND comment_approved = '1'" );

  //  update offset
  $offset = get_option( 'fv_gravatar_cache_offset');
  if( $offset >= $count || ( !isset($offset) || empty($offset) ) ) {
    $offset = 0;
    update_option( 'fv_gravatar_cache_offset', $offset );
  }
  //  get 25 email addresses to be processed
  $emails = $wpdb->get_col( "SELECT comment_author_email FROM $wpdb->comments WHERE comment_author_email != '' AND comment_approved = '1' GROUP BY comment_author_email LIMIT {$offset},25" );
  $FV_Gravatar_Cache->OpenLog();
  $FV_Gravatar_Cache->Log( 'Processing '.count( $emails ).' gravatars'."\r\n" );
  //  process email addresses
  
  $start = microtime(true);
  $iCompleted = 0;
  foreach( $emails AS $email ) {
    $FV_Gravatar_Cache->Cron( $email, $options['size'] );
    $iCompleted++;
    $time_taken = microtime(true) - $start;
    if( $time_taken > 2) {
      break;
    }
  }
  
  //  increase offset
  update_option( 'fv_gravatar_cache_offset', $offset+$iCompleted);
  //  update last cron run date
  $FV_Gravatar_Cache->CloseLog();
  $options = get_option( 'fv_gravatar_cache');
  $options['last_run'] = date(DATE_RFC822);
  update_option( 'fv_gravatar_cache', $options);
  //update_option( 'fv_gravatar_cache_cron_alive', 'yes!' ); 
}

$FV_Gravatar_Cache = new FV_Gravatar_Cache;

?>