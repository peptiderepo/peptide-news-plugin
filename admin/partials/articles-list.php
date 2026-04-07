<?php
/**
 * Articles management partial.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$table   = $wpdb->prefix . 'peptide_news_articles';
$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page = 25;
$offset  = ( $page - 1 ) * $per_page;

$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$articles = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$table} ORDER BY published_at DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
) );

$total_pages = ceil( $total / $per_page );
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Stored Articles', 'peptide-news' ); ?> <span class="title-count">(<?php echo esc_html( number_format( $total ) ); ?>)</span></h1>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:4%">ID</th>
                <th style="width:28%"><?php esc_html_e( 'Title', 'peptide-news' ); ?></th>
                <th style="width:10%"><?php esc_html_e( 'Source', 'peptide-news' ); ?></th>
                <th style="width:15%"><?php esc_html_e( 'AI Keywords', 'peptide-news' ); ?></th>
                <th style="width:23%"><?php esc_html_e( 'AI Summary', 'peptide-news' ); ?></th>
                <th style="width:8%"><?php esc_html_e( 'Published', 'peptide-news' ); ?></th>
                <th style="width:8%"><?php esc_html_e( 'Fetched', 'peptide-news' ); ?></th>
                <th style="width:4%"><?php esc_html_e( 'Active', 'peptide-news' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $articles ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No articles found. Run a fetch first.', 'peptide-news' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $articles as $article ) : ?>
                    <tr>
                        <td><?php echo esc_html( $article->id ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $article->source_url ); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html( wp_trim_words( $article->title, 12 ) ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $article->source ); ?></td>
                        <td>
                            <?php if ( ! empty( $article->tags ) ) : ?>
                                <span style="font-size:12px;line-height:1.4;"><?php echo esc_html( wp_trim_words( $article->tags, 10 ) ); ?></span>
                            <?php else : ?>
                                <span style="color:#999;font-style:italic;"><?php esc_html_e( 'Pending', 'peptide-news' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $article->ai_summary ) ) : ?>
                                <span style="font-size:12px;line-height:1.4;" title="<?php echo esc_attr( $article->ai_summary ); ?>"><?php echo esc_html( wp_trim_words( $article->ai_summary, 15 ) ); ?></span>
                            <?php else : ?>
                                <span style="color:#999;font-style:italic;"><?php esc_html_e( 'Pending', 'peptide-news' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $article->published_at ) ) ); ?></td>
                        <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $article->fetched_at ) ) ); ?></td>
                        <td><?php echo $article->is_active ? '<span style="color:green;">&#10003;</span>' : '<span style="color:#ccc;">&#10007;</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ) );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
