<?php
/**
 * Geolocation handler - Geocodes addresses and injects GPS coordinates into image EXIF
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hozio_Image_Optimizer_Geolocation {

    /**
     * Cache for geocoding results to avoid duplicate API calls
     */
    private static $geocode_cache = array();

    /**
     * Geocode a location string to get latitude and longitude
     *
     * @param string $location Location string (e.g., "New York, NY" or "123 Main St, Boston, MA")
     * @return array|false Array with 'lat' and 'lng' or false on failure
     */
    public static function geocode($location) {
        if (empty($location)) {
            return false;
        }

        // Check cache first
        $cache_key = md5($location);
        if (isset(self::$geocode_cache[$cache_key])) {
            return self::$geocode_cache[$cache_key];
        }

        // Check transient cache (persists across requests)
        $transient_key = 'hozio_geo_' . $cache_key;
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            self::$geocode_cache[$cache_key] = $cached;
            return $cached;
        }

        // Use OpenStreetMap Nominatim API (free, no API key required)
        $url = add_query_arg(array(
            'q' => urlencode($location),
            'format' => 'json',
            'limit' => 1,
        ), 'https://nominatim.openstreetmap.org/search');

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'HozioImageOptimizer/1.0 WordPress Plugin',
            ),
        ));

        if (is_wp_error($response)) {
            Hozio_Image_Optimizer_Helpers::log('Geocoding error: ' . $response->get_error_message(), 'error');
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data[0]['lat']) || !isset($data[0]['lon'])) {
            Hozio_Image_Optimizer_Helpers::log('Geocoding failed for location: ' . $location, 'warning');
            return false;
        }

        $result = array(
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
            'display_name' => $data[0]['display_name'] ?? $location,
        );

        // Cache the result
        self::$geocode_cache[$cache_key] = $result;
        set_transient($transient_key, $result, DAY_IN_SECONDS * 30); // Cache for 30 days

        return $result;
    }

    /**
     * Convert decimal degrees to EXIF GPS format (degrees, minutes, seconds)
     *
     * @param float $decimal Decimal degrees
     * @return array Array of [degrees, minutes, seconds] as fractions
     */
    public static function decimal_to_dms($decimal) {
        $decimal = abs($decimal);

        $degrees = floor($decimal);
        $minutes_decimal = ($decimal - $degrees) * 60;
        $minutes = floor($minutes_decimal);
        $seconds = ($minutes_decimal - $minutes) * 60;

        // Return as rational numbers (fractions) for EXIF
        return array(
            array($degrees, 1),
            array($minutes, 1),
            array(round($seconds * 10000), 10000),
        );
    }

    /**
     * Write GPS coordinates to an image file
     *
     * @param string $image_path Path to the image file
     * @param float $latitude Latitude in decimal degrees
     * @param float $longitude Longitude in decimal degrees
     * @return bool True on success, false on failure
     */
    public static function write_gps_to_image($image_path, $latitude, $longitude) {
        if (!file_exists($image_path)) {
            Hozio_Image_Optimizer_Helpers::log('GPS write failed: File not found - ' . $image_path, 'error');
            return false;
        }

        $mime_type = mime_content_type($image_path);

        // For WebP/AVIF, only exiftool can write GPS via XMP
        if ($mime_type === 'image/webp' || $mime_type === 'image/avif') {
            $result = self::write_gps_with_exiftool($image_path, $latitude, $longitude);
            if ($result) {
                return true;
            }
            Hozio_Image_Optimizer_Helpers::log('GPS write to WebP/AVIF requires exiftool. Format: ' . $mime_type, 'warning');
            return false;
        }

        // For JPEG, try multiple methods
        if ($mime_type === 'image/jpeg' || $mime_type === 'image/jpg') {
            // Try exiftool first (most reliable method)
            $result = self::write_gps_with_exiftool($image_path, $latitude, $longitude);
            if ($result) {
                return true;
            }

            // Fallback to native PHP JPEG manipulation
            $result = self::write_gps_native($image_path, $latitude, $longitude);
            if ($result) {
                return true;
            }
        }

        Hozio_Image_Optimizer_Helpers::log('GPS write failed: Unsupported format or no method worked. Format: ' . $mime_type, 'warning');
        return false;
    }

    /**
     * Check if exiftool is available (bundled or system)
     */
    private static function get_exiftool_path() {
        // Check cached result
        static $exiftool_path = null;
        if ($exiftool_path !== null) {
            return $exiftool_path;
        }

        // First, check for bundled exiftool in plugin directory
        $bundled_exiftool = HOZIO_IMAGE_OPTIMIZER_DIR . 'vendor/exiftool/exiftool';
        if (file_exists($bundled_exiftool)) {
            // Try to run bundled exiftool with perl
            $perl_paths = array('perl', '/usr/bin/perl', '/usr/local/bin/perl');
            foreach ($perl_paths as $perl) {
                $output = array();
                $return_var = -1;
                $test_cmd = escapeshellcmd($perl) . ' ' . escapeshellarg($bundled_exiftool) . ' -ver 2>&1';
                @exec($test_cmd, $output, $return_var);
                if ($return_var === 0) {
                    // Store the full command with perl
                    $exiftool_path = escapeshellcmd($perl) . ' ' . escapeshellarg($bundled_exiftool);
                    Hozio_Image_Optimizer_Helpers::log('Using bundled exiftool with perl at: ' . $perl, 'info');
                    return $exiftool_path;
                }
            }
        }

        // Fall back to system-installed exiftool
        $possible_paths = array(
            'exiftool',                           // In PATH
            '/usr/bin/exiftool',
            '/usr/local/bin/exiftool',
            '/opt/local/bin/exiftool',
        );

        foreach ($possible_paths as $path) {
            $output = array();
            $return_var = -1;
            @exec($path . ' -ver 2>&1', $output, $return_var);
            if ($return_var === 0) {
                $exiftool_path = $path;
                Hozio_Image_Optimizer_Helpers::log('Found system exiftool at: ' . $path, 'info');
                return $exiftool_path;
            }
        }

        $exiftool_path = false;
        return false;
    }

    /**
     * Write GPS using exiftool (most reliable method)
     */
    private static function write_gps_with_exiftool($image_path, $latitude, $longitude) {
        $exiftool = self::get_exiftool_path();
        if (!$exiftool) {
            Hozio_Image_Optimizer_Helpers::log('exiftool not available', 'info');
            return false;
        }

        // Store original file hash to verify integrity
        $original_hash = md5_file($image_path);
        $original_size = filesize($image_path);

        // Determine N/S and E/W references
        $lat_ref = $latitude >= 0 ? 'N' : 'S';
        $lng_ref = $longitude >= 0 ? 'E' : 'W';

        // Use absolute values for the coordinates
        $abs_lat = abs($latitude);
        $abs_lng = abs($longitude);

        // Build exiftool command
        // Note: $exiftool may already be escaped (e.g., "perl /path/to/exiftool")
        // -overwrite_original prevents creating backup files
        $command = sprintf(
            '%s -overwrite_original -GPSLatitude=%f -GPSLatitudeRef=%s -GPSLongitude=%f -GPSLongitudeRef=%s -GPSVersionID="2.2.0.0" %s 2>&1',
            $exiftool,  // Already escaped if bundled, or plain path if system
            $abs_lat,
            $lat_ref,
            $abs_lng,
            $lng_ref,
            escapeshellarg($image_path)
        );

        Hozio_Image_Optimizer_Helpers::log('Executing exiftool command: ' . $command, 'debug');

        $output = array();
        $return_var = -1;
        exec($command, $output, $return_var);

        if ($return_var === 0) {
            // Verify the image is still valid after exiftool modification
            clearstatcache(true, $image_path);
            $new_size = filesize($image_path);

            // Check if file is now empty or significantly corrupted
            if ($new_size === 0 || $new_size < 100) {
                Hozio_Image_Optimizer_Helpers::log('exiftool corrupted file - file size now: ' . $new_size, 'error');
                return false;
            }

            // Verify it's still a valid image
            $image_info = @getimagesize($image_path);
            if (!$image_info) {
                Hozio_Image_Optimizer_Helpers::log('exiftool corrupted file - no longer valid image', 'error');
                return false;
            }

            Hozio_Image_Optimizer_Helpers::log("GPS written with exiftool: {$latitude}, {$longitude}", 'info');
            return true;
        }

        Hozio_Image_Optimizer_Helpers::log('exiftool GPS write failed: ' . implode(' ', $output), 'error');
        return false;
    }

    /**
     * Write GPS using native PHP JPEG manipulation
     * Uses pel/pel library structure for proper EXIF handling
     */
    private static function write_gps_native($image_path, $latitude, $longitude) {
        // Check if PEL library is available (can be installed via Composer)
        if (class_exists('lsolesen\pel\PelJpeg')) {
            return self::write_gps_with_pel($image_path, $latitude, $longitude);
        }

        // Manual EXIF GPS injection for JPEG
        // This is a simplified approach that works for most JPEGs
        return self::write_gps_manual($image_path, $latitude, $longitude);
    }

    /**
     * Write GPS using PEL library (if available)
     */
    private static function write_gps_with_pel($image_path, $latitude, $longitude) {
        try {
            $jpeg = new \lsolesen\pel\PelJpeg($image_path);
            $exif = $jpeg->getExif();

            if ($exif === null) {
                $exif = new \lsolesen\pel\PelExif();
                $jpeg->setExif($exif);
            }

            $tiff = $exif->getTiff();
            if ($tiff === null) {
                $tiff = new \lsolesen\pel\PelTiff();
                $exif->setTiff($tiff);
            }

            $ifd0 = $tiff->getIfd();
            if ($ifd0 === null) {
                $ifd0 = new \lsolesen\pel\PelIfd(\lsolesen\pel\PelIfd::IFD0);
                $tiff->setIfd($ifd0);
            }

            // Get or create GPS IFD
            $gps_ifd = $ifd0->getSubIfd(\lsolesen\pel\PelIfd::GPS);
            if ($gps_ifd === null) {
                $gps_ifd = new \lsolesen\pel\PelIfd(\lsolesen\pel\PelIfd::GPS);
                $ifd0->addSubIfd($gps_ifd);
            }

            // Set GPS Version
            $gps_ifd->addEntry(new \lsolesen\pel\PelEntryByte(
                \lsolesen\pel\PelTag::GPS_VERSION_ID,
                2, 2, 0, 0
            ));

            // Set Latitude
            $lat_ref = $latitude >= 0 ? 'N' : 'S';
            $gps_ifd->addEntry(new \lsolesen\pel\PelEntryAscii(
                \lsolesen\pel\PelTag::GPS_LATITUDE_REF,
                $lat_ref
            ));

            $lat_dms = self::decimal_to_dms($latitude);
            $gps_ifd->addEntry(new \lsolesen\pel\PelEntryRational(
                \lsolesen\pel\PelTag::GPS_LATITUDE,
                $lat_dms[0], $lat_dms[1], $lat_dms[2]
            ));

            // Set Longitude
            $lng_ref = $longitude >= 0 ? 'E' : 'W';
            $gps_ifd->addEntry(new \lsolesen\pel\PelEntryAscii(
                \lsolesen\pel\PelTag::GPS_LONGITUDE_REF,
                $lng_ref
            ));

            $lng_dms = self::decimal_to_dms($longitude);
            $gps_ifd->addEntry(new \lsolesen\pel\PelEntryRational(
                \lsolesen\pel\PelTag::GPS_LONGITUDE,
                $lng_dms[0], $lng_dms[1], $lng_dms[2]
            ));

            $jpeg->saveFile($image_path);

            Hozio_Image_Optimizer_Helpers::log("GPS written with PEL: {$latitude}, {$longitude}", 'info');
            return true;
        } catch (Exception $e) {
            Hozio_Image_Optimizer_Helpers::log('PEL GPS write failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Manual GPS EXIF injection using raw JPEG manipulation
     * This creates proper EXIF GPS IFD structure
     */
    private static function write_gps_manual($image_path, $latitude, $longitude) {
        try {
            $data = file_get_contents($image_path);
            if ($data === false) {
                return false;
            }

            // Check JPEG signature
            if (substr($data, 0, 2) !== "\xFF\xD8") {
                Hozio_Image_Optimizer_Helpers::log('Not a valid JPEG file', 'error');
                return false;
            }

            // Build GPS EXIF segment
            $gps_data = self::build_gps_exif_segment($latitude, $longitude);

            // Find position to insert EXIF (after SOI marker, before any other segment)
            $insert_pos = 2;

            // Check if there's already an APP1 (EXIF) segment
            $pos = 2;
            while ($pos < strlen($data) - 4) {
                if ($data[$pos] !== "\xFF") {
                    break;
                }
                $marker = ord($data[$pos + 1]);

                // APP1 marker (EXIF)
                if ($marker === 0xE1) {
                    $segment_length = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);

                    // Check if it's EXIF
                    if (substr($data, $pos + 4, 4) === 'Exif') {
                        // Remove existing EXIF segment, we'll add our own
                        $data = substr($data, 0, $pos) . substr($data, $pos + 2 + $segment_length);
                        break;
                    }

                    $pos += 2 + $segment_length;
                    continue;
                }

                // Skip other APP markers
                if ($marker >= 0xE0 && $marker <= 0xEF) {
                    $segment_length = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
                    $insert_pos = $pos + 2 + $segment_length;
                    $pos = $insert_pos;
                    continue;
                }

                break;
            }

            // Insert GPS EXIF at the appropriate position
            $new_data = substr($data, 0, $insert_pos) . $gps_data . substr($data, $insert_pos);

            // Write back
            $result = file_put_contents($image_path, $new_data);

            if ($result !== false) {
                Hozio_Image_Optimizer_Helpers::log("GPS written manually: {$latitude}, {$longitude}", 'info');
                return true;
            }

            return false;
        } catch (Exception $e) {
            Hozio_Image_Optimizer_Helpers::log('Manual GPS write failed: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Build a complete EXIF APP1 segment with GPS data
     */
    private static function build_gps_exif_segment($latitude, $longitude) {
        // GPS references
        $lat_ref = $latitude >= 0 ? 'N' : 'S';
        $lng_ref = $longitude >= 0 ? 'E' : 'W';

        // Convert to DMS
        $lat_dms = self::decimal_to_dms($latitude);
        $lng_dms = self::decimal_to_dms($longitude);

        // Build TIFF structure (little-endian)
        $tiff = '';

        // TIFF header: "II" (little-endian), 42, offset to IFD0
        $tiff .= "II";
        $tiff .= pack('v', 42);     // TIFF magic
        $tiff .= pack('V', 8);      // Offset to IFD0

        // IFD0 (at offset 8)
        // 1 entry pointing to GPS IFD
        $tiff .= pack('v', 1);      // Number of entries

        // GPS IFD pointer entry (tag 0x8825)
        $tiff .= pack('v', 0x8825); // Tag: GPSInfoIFDPointer
        $tiff .= pack('v', 4);      // Type: LONG
        $tiff .= pack('V', 1);      // Count
        $tiff .= pack('V', 26);     // Value: offset to GPS IFD (8 + 2 + 12 + 4 = 26)

        // Next IFD offset (0 = none)
        $tiff .= pack('V', 0);

        // GPS IFD (at offset 26)
        // Entries: GPSVersionID, GPSLatitudeRef, GPSLatitude, GPSLongitudeRef, GPSLongitude
        $tiff .= pack('v', 5);      // Number of entries

        $data_offset = 26 + 2 + (5 * 12) + 4; // After IFD entries

        // Entry 1: GPSVersionID (tag 0)
        $tiff .= pack('v', 0);      // Tag
        $tiff .= pack('v', 1);      // Type: BYTE
        $tiff .= pack('V', 4);      // Count
        $tiff .= pack('CCCC', 2, 2, 0, 0); // Value inline

        // Entry 2: GPSLatitudeRef (tag 1)
        $tiff .= pack('v', 1);      // Tag
        $tiff .= pack('v', 2);      // Type: ASCII
        $tiff .= pack('V', 2);      // Count
        $tiff .= $lat_ref . "\0\0\0"; // Value inline (padded)

        // Entry 3: GPSLatitude (tag 2) - 3 rationals = 24 bytes
        $tiff .= pack('v', 2);      // Tag
        $tiff .= pack('v', 5);      // Type: RATIONAL
        $tiff .= pack('V', 3);      // Count
        $tiff .= pack('V', $data_offset); // Offset to data
        $lat_data_offset = $data_offset;
        $data_offset += 24;

        // Entry 4: GPSLongitudeRef (tag 3)
        $tiff .= pack('v', 3);      // Tag
        $tiff .= pack('v', 2);      // Type: ASCII
        $tiff .= pack('V', 2);      // Count
        $tiff .= $lng_ref . "\0\0\0"; // Value inline (padded)

        // Entry 5: GPSLongitude (tag 4) - 3 rationals = 24 bytes
        $tiff .= pack('v', 4);      // Tag
        $tiff .= pack('v', 5);      // Type: RATIONAL
        $tiff .= pack('V', 3);      // Count
        $tiff .= pack('V', $data_offset); // Offset to data

        // Next IFD offset (0 = none)
        $tiff .= pack('V', 0);

        // Data area: Latitude rationals
        $tiff .= pack('V', $lat_dms[0][0]); // Degrees numerator
        $tiff .= pack('V', $lat_dms[0][1]); // Degrees denominator
        $tiff .= pack('V', $lat_dms[1][0]); // Minutes numerator
        $tiff .= pack('V', $lat_dms[1][1]); // Minutes denominator
        $tiff .= pack('V', $lat_dms[2][0]); // Seconds numerator
        $tiff .= pack('V', $lat_dms[2][1]); // Seconds denominator

        // Data area: Longitude rationals
        $tiff .= pack('V', $lng_dms[0][0]); // Degrees numerator
        $tiff .= pack('V', $lng_dms[0][1]); // Degrees denominator
        $tiff .= pack('V', $lng_dms[1][0]); // Minutes numerator
        $tiff .= pack('V', $lng_dms[1][1]); // Minutes denominator
        $tiff .= pack('V', $lng_dms[2][0]); // Seconds numerator
        $tiff .= pack('V', $lng_dms[2][1]); // Seconds denominator

        // Build APP1 segment
        $exif_header = "Exif\0\0";
        $segment_data = $exif_header . $tiff;
        $segment_length = strlen($segment_data) + 2;

        // APP1 marker + length + data
        $app1 = "\xFF\xE1" . pack('n', $segment_length) . $segment_data;

        return $app1;
    }

    /**
     * Inject GPS coordinates directly into an image (without geocoding)
     *
     * @param string $image_path Path to the image
     * @param float $latitude Latitude coordinate
     * @param float $longitude Longitude coordinate
     * @return array Result with success status
     */
    public static function inject_coordinates($image_path, $latitude, $longitude) {
        if (!get_option('hozio_enable_geolocation', true)) {
            return array(
                'success' => false,
                'message' => 'Geolocation is disabled',
            );
        }

        // Validate coordinates
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return array(
                'success' => false,
                'message' => 'Invalid coordinates provided',
            );
        }

        $lat = floatval($latitude);
        $lng = floatval($longitude);

        // Validate range
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return array(
                'success' => false,
                'message' => 'Coordinates out of valid range',
            );
        }

        // Write GPS to image
        $written = self::write_gps_to_image($image_path, $lat, $lng);

        return array(
            'success' => $written,
            'latitude' => $lat,
            'longitude' => $lng,
            'manual' => true,
            'message' => $written
                ? sprintf('GPS coordinates injected (manual): %f, %f', $lat, $lng)
                : 'Could not write GPS coordinates to image',
        );
    }

    /**
     * Inject geolocation into an image based on location string
     *
     * @param string $image_path Path to the image
     * @param string $location Location string to geocode
     * @return array Result with success status and coordinates
     */
    public static function inject_geolocation($image_path, $location) {
        if (!get_option('hozio_enable_geolocation', true)) {
            return array(
                'success' => false,
                'message' => 'Geolocation is disabled',
            );
        }

        if (empty($location)) {
            return array(
                'success' => false,
                'message' => 'No location provided',
            );
        }

        // Geocode the location
        $coords = self::geocode($location);

        if (!$coords) {
            return array(
                'success' => false,
                'message' => 'Could not geocode location: ' . $location,
            );
        }

        // Write GPS to image
        $written = self::write_gps_to_image($image_path, $coords['lat'], $coords['lng']);

        return array(
            'success' => $written,
            'latitude' => $coords['lat'],
            'longitude' => $coords['lng'],
            'display_name' => $coords['display_name'],
            'message' => $written
                ? sprintf('GPS coordinates injected: %f, %f', $coords['lat'], $coords['lng'])
                : 'GPS coordinates found but could not be written to image (ImageMagick required)',
        );
    }

    /**
     * Get GPS coordinates from an image
     *
     * @param string $image_path Path to image
     * @return array|false Array with lat/lng or false
     */
    public static function get_gps_from_image($image_path) {
        if (!function_exists('exif_read_data')) {
            return false;
        }

        $exif = @exif_read_data($image_path, 'GPS');

        if (!$exif || !isset($exif['GPSLatitude']) || !isset($exif['GPSLongitude'])) {
            return false;
        }

        $lat = self::gps_to_decimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
        $lng = self::gps_to_decimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');

        return array(
            'lat' => $lat,
            'lng' => $lng,
        );
    }

    /**
     * Convert GPS EXIF data to decimal degrees
     */
    private static function gps_to_decimal($gps_data, $ref) {
        $degrees = self::exif_coord_to_number($gps_data[0]);
        $minutes = self::exif_coord_to_number($gps_data[1]);
        $seconds = self::exif_coord_to_number($gps_data[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if ($ref === 'S' || $ref === 'W') {
            $decimal = -$decimal;
        }

        return $decimal;
    }

    /**
     * Convert EXIF coordinate string to number
     */
    private static function exif_coord_to_number($coord) {
        if (is_string($coord) && strpos($coord, '/') !== false) {
            $parts = explode('/', $coord);
            return (float) $parts[0] / (float) $parts[1];
        }
        return (float) $coord;
    }

    /**
     * Test geocoding functionality
     */
    public static function test_geocoding($location) {
        $result = self::geocode($location);

        if ($result) {
            return array(
                'success' => true,
                'latitude' => $result['lat'],
                'longitude' => $result['lng'],
                'display_name' => $result['display_name'],
            );
        }

        return array(
            'success' => false,
            'message' => 'Could not geocode the location',
        );
    }

    /**
     * Search for US locations (for autocomplete)
     *
     * @param string $query Search query
     * @return array Array of matching locations
     */
    public static function search_us_locations($query) {
        // Use OpenStreetMap Nominatim API for US-only search
        $url = add_query_arg(array(
            'q' => urlencode($query),
            'format' => 'json',
            'limit' => 8,
            'countrycodes' => 'us',
            'addressdetails' => 1,
        ), 'https://nominatim.openstreetmap.org/search');

        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'headers' => array(
                'User-Agent' => 'HozioImageOptimizer/1.0 WordPress Plugin',
            ),
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            return array();
        }

        $locations = array();
        foreach ($data as $item) {
            // Only include places (cities, towns, villages, etc.)
            $type = $item['type'] ?? '';
            $class = $item['class'] ?? '';

            // Filter to place types we want
            $allowed_types = array('city', 'town', 'village', 'hamlet', 'suburb', 'neighbourhood', 'borough', 'administrative');
            $allowed_classes = array('place', 'boundary');

            if (!in_array($type, $allowed_types) && !in_array($class, $allowed_classes)) {
                continue;
            }

            $address = $item['address'] ?? array();

            // Build a clean location name
            $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['hamlet'] ?? $address['suburb'] ?? '';
            $state = $address['state'] ?? '';

            if (empty($city) && !empty($item['display_name'])) {
                // Extract first part of display name
                $parts = explode(',', $item['display_name']);
                $city = trim($parts[0]);
            }

            if (empty($city) || empty($state)) {
                continue;
            }

            // Get state abbreviation
            $state_abbr = self::get_state_abbreviation($state);

            $locations[] = array(
                'name' => $city . ', ' . $state_abbr,
                'display_name' => $item['display_name'],
                'lat' => $item['lat'],
                'lng' => $item['lon'],
            );
        }

        // Remove duplicates
        $seen = array();
        $unique_locations = array();
        foreach ($locations as $loc) {
            if (!in_array($loc['name'], $seen)) {
                $seen[] = $loc['name'];
                $unique_locations[] = $loc;
            }
        }

        return array_slice($unique_locations, 0, 6);
    }

    /**
     * Get US state abbreviation
     */
    private static function get_state_abbreviation($state) {
        $states = array(
            'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
            'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
            'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID',
            'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS',
            'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD',
            'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS',
            'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV',
            'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY',
            'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK',
            'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC',
            'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT',
            'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV',
            'Wisconsin' => 'WI', 'Wyoming' => 'WY', 'District of Columbia' => 'DC',
        );

        return $states[$state] ?? $state;
    }
}
