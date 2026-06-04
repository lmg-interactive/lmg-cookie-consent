<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Consent log — stores one row per accept / reject / custom / withdraw / gpc
 * event. No raw IP/UA is retained; both are hashed with a site salt so logs
 * can satisfy the "demonstrate consent" GDPR requirement without being a PII
 * dump. Rows are purged on a retention schedule (see LMG_Consent::run_purge).
 *
 * Table name retained as {$prefix}m_consent_log across the rebrand so existing
 * audit history is preserved.
 */
final class LMG_Consent_Log {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'm_consent_log';
    }

    public static function install() {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            consent_id  VARCHAR(64)     NOT NULL DEFAULT '',
            action      VARCHAR(20)     NOT NULL DEFAULT '',
            categories  TEXT            NOT NULL,
            schema_ver  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            ip_hash     CHAR(64)        NOT NULL DEFAULT '',
            ua_hash     CHAR(64)        NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY consent_id (consent_id),
            KEY ts (ts)
        ) {$charset};";
        dbDelta( $sql );
    }

    public static function record( array $row ) {
        global $wpdb;
        $wpdb->insert(
            self::table(),
            [
                'consent_id'  => $row['consent_id'] ?? '',
                'action'      => $row['action']     ?? '',
                'categories'  => $row['categories'] ?? '[]',
                'schema_ver'  => (int) ( $row['schema_ver'] ?? 1 ),
                'ip_hash'     => $row['ip_hash']    ?? '',
                'ua_hash'     => $row['ua_hash']    ?? '',
            ],
            [ '%s','%s','%s','%d','%s','%s' ]
        );
    }

    /**
     * Delete consent records older than $days (storage limitation / GDPR).
     *
     * @param int $days Retention window in days.
     * @return int|false Rows deleted, or false on error.
     */
    public static function purge( $days ) {
        global $wpdb;
        $days  = max( 1, (int) $days );
        $table = self::table();
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE ts < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
                $days
            )
        );
    }
}
