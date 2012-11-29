<?php
/*
Plugin Name: Most Popular Content
Plugin URI: http://aeideas.stratecomm.net
Description: Most Popular content tabbed widget to show: most viewed, most commented, most shared posts in 3 tabs.
Author: vietdt
Version: 1.0
*/

global $sharing_stats_db_version;
$sharing_stats_db_version = "1.0";

global $wpdb;
global $sharing_stats_table_name;
$sharing_stats_table_name = $wpdb->prefix . "sharing_stats";

function most_popular_content_install() {
   global $wpdb;
   global $sharing_stats_table_name;
   global $sharing_stats_db_version;
      
   $sql = "CREATE TABLE $sharing_stats_table_name (
  id mediumint(9) NOT NULL AUTO_INCREMENT,
  time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
  share_service tinytext NOT NULL,
  post_id mediumint(9) NOT NULL,
  UNIQUE KEY id (id)
    );";

   require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
   dbDelta($sql);
 
   add_option("sharing_stats_db_version", $sharing_stats_db_version);
}
// run on plugin activation
register_activation_hook(__FILE__,'most_popular_content_install');

// register MostPopularContentWidget
add_action('widgets_init', create_function('', 'return register_widget("MostPopularContentWidget");'));

/**
 * MostPopularContentWidget Class
 */
class MostPopularContentWidget extends WP_Widget {
    /** constructor */
    function MostPopularContentWidget() {
        /* Widget settings. */
        $widget_ops = array( 'classname' => 'MostPopularContentWidget', 'description' => __('Widget that shows most viewed, most commented, most shared posts in 3 tabs') );

        /* Widget control settings. */
        $control_ops = array('id_base' => 'most-popular-content-widget' );

        /* Create the widget. */
        parent::WP_Widget( 'most-popular-content-widget', __('Most Popular Content'), $widget_ops, $control_ops );
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {
        extract( $args );

        $title = $instance['title'];
        if ( empty($title) ) $title = __( 'Most Popular Content' );
        $content = render_most_popular_content($instance);

        if ($content) {
            echo $before_widget;
            echo $before_title;
            echo $title;
            echo $after_title;
            echo $content;
            echo $after_widget;
        }
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {
        $instance = $old_instance;
        // validate data
        $instance['title'] = strip_tags($new_instance['title']);
        $instance['show'] = absint($new_instance['show']);

        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {
        /* Set up some default widget settings. */
        $defaults = array( 'title' => '', 'show' => 3 );
        $instance = wp_parse_args( (array) $instance, $defaults );

        $title = esc_attr($instance['title']);
        $show = absint($instance['show']);
?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show'); ?>"><?php _e('Number of posts to show:'); ?>
            <input class="widefat" id="<?php echo $this->get_field_id('show'); ?>" name="<?php echo $this->get_field_name('show'); ?>" type="text" value="<?php echo $show; ?>" /></label>
        </p>
<?php
    }
} // class MostPopularContentWidget

function render_most_popular_content($instance) {
    /* This widget shows on ALL views except search */
    if ( is_search() ) return '';

    // first look in cache
    $output = get_transient("most-popular-content");
    $expire = 12*60*60; // cache in 12hrs ?

    if ( $output === false ) {
        // Not present in cache so load it
        $output = '';
        $limit = $instance['show'];

        $most_viewed_posts = get_most_viewed($limit);
        $most_commented_posts = get_most_commented($limit);
        $most_shared_posts = get_most_shared($limit);

        if ( $most_viewed_posts || $most_commented_posts || $most_shared_posts ) {
            $output .= '<ul class="tabs">';
            $output .= '<li><a href="#">Viewed</a></li>';
            $output .= '<li><a href="#">Commented</a></li>';
            $output .= '<li><a href="#">Shared</a></li>';
            $output .= '</ul>';

            $output .= '<div class="tabpanes">';
            foreach ( $panes = array($most_viewed_posts, $most_commented_posts, $most_shared_posts) as $posts ) {
                $output .= '<div class="tabpane">';
                if ( $posts ) {
                    $post_count = 0;
                    foreach ( $posts as $post ) {
                        $ssclass = $post_count==0 ? 'first' : ($post_count==count($posts)-1 ? 'last' : '');
                        $output .= "<dl class='$ssclass'>";
                        $output .= '<dt>';
                        // render post's categories
                        $post_categories = wp_get_post_categories( $post['post_id'] );
                        $cnt = 0;
                        foreach($post_categories as $c) {
                            $cat = get_category( $c );
                            $output .= '<a href="'.get_category_link($cat->cat_ID).'">';
                            $output .= $cat->cat_name;
                            $output .= '</a>';
                            $cnt++;
                            if ( $cnt <> count($post_categories) )
                                $output .= ', ';
                        }
                        $output .= '</dt>';
                        // display post's title with link
                        $post_title = $post['post_title'] ? $post['post_title'] : get_the_title( $post['post_id'] );
                        $output .= '<dd><a href="' . get_permalink( $post['post_id'] ) . '">' . $post_title . '</a></dd>';
                        $output .= '</dl>';
                        $post_count++;
                    }
                }
                $output .= '</div>';
            }
            $output .= '</div>';
        }

        // save the output to cache
        if ($output)
            set_transient("most-popular-content", $output, $expire);
    }

    return $output;
}

function get_most_viewed($limit) {
    $period = 7; // get most viewed posts in 7 days
    $posts = array();
    if ( function_exists( 'stats_get_csv' ) ) {
        $post_ids = array();
        // workaround: set api limit 5 times more than $limit to get enough posts after filtered
        $api_limit = 5 * $limit;
        foreach ( $top_posts = stats_get_csv( 'postviews', "days=$period&limit=$api_limit" ) as $post )
            $post_ids[] = $post['post_id'];
        // cache
        get_posts( array( 'include' => join( ',', array_unique( $post_ids ) ) ) );

        $count = 0;
        foreach ( $top_posts as $post ) {
            if ( !$post['post_id'] ) continue; // advoid empty id
            $post_object = get_post( $post['post_id'] );
            // advoid empty post, only get post type
            if ( !$post_object || 'post' != $post_object->post_type ) continue;
            $posts[] = array('post_id' => $post['post_id'],
                             'post_title' => $post_object->post_title,
                             'count' => $post['views']);
            $count++;
            if ( $count >= $limit ) break;
        }
    }
    return $posts;
}

function get_most_commented($limit) {
    global $wpdb;
    $posts = array();

    $results = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, comment_count FROM $wpdb->posts WHERE post_type = 'post' AND post_status='publish' ORDER BY comment_count DESC LIMIT %d", $limit ) );
    // convert rows to array
    foreach ($results as $result) {
        $posts[] = array('post_id' => $result->ID,
                         'post_title' => $result->post_title,
                         'count' => $result->comment_count);
    }

    return $posts;
}

function get_most_shared($limit) {
    global $wpdb;
    global $sharing_stats_table_name;

    $results = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, COUNT(post_id) as count FROM $sharing_stats_table_name GROUP BY post_id ORDER BY count DESC" ) );

    // convert rows to array
    $posts = array();
    $count = 0;
    foreach ($results as $result) {
        if ( !$result->post_id ) continue;
        $post_object = get_post( $result->post_id );
        if ( !$post_object || 'post' != $post_object->post_type ) continue;
        $posts[] = array('post_id' => $result->post_id,
                         'post_title' => $post_object->post_title,
                         'count' => $result->count);
        $count++;
        if ( $count >= $limit ) break;
    }

    return $posts;
}

// store sharing stats in a custom table
function aeideas_store_stats( $share_data ) {
    global $wpdb;
    global $sharing_stats_table_name;
    $service_name = strtolower( $share_data['service']->get_id() );
    $post = $share_data['post'];

    $rows_affected = $wpdb->insert( $sharing_stats_table_name, array( 'time' => current_time('mysql'), 'share_service' => $service_name, 'post_id' => $post->ID ) );
}
// called on every share by jetpack
add_action('sharing_bump_stats','aeideas_store_stats');

?>