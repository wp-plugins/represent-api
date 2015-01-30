<?php
/*
Plugin Name: Represent API
Plugin URI: http://wordpress.org/plugins/represent-api/
Description: The Represent API plugin allows developers to easily create plugins that harness the Represent API.
Version: 1.1
Author: Open North Inc.
Author URI: http://opennorth.ca/
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

/**
 * @file
 *   Send request to the Represent API.
 *
 * @see https://represent.opennorth.ca/
 * @see https://represent.opennorth.ca/api/
 */

// Make sure we don't expose any info if called directly
if ( ! function_exists( 'add_action' ) ) {
  echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
  exit;
}

/**
 * @return array
 *   The available representative sets
 */
function represent_representative_sets() {
  return represent_resource_sets( 'representative' );
}

/**
 * @return array
 *   The available boundary sets
 */
function represent_boundary_sets() {
  return represent_resource_sets( 'boundary' );
}

/**
 * @param string $set
 *   The machine name of a representative set, eg "house-of-commons"
 * @return array
 *   The representatives in the representative set
 */
function represent_representatives_by_set( $set, $fields = array() ) {
  return represent_resources_by_set( $set, 'representatives' );
}

/**
 * @param string $set
 *   The machine name of a boundary set, eg "federal-electoral-districts"
 * @return array
 *   The boundaries in the boundary set
 */
function represent_boundaries_by_set( $set ) {
  return represent_resources_by_set( $set, 'boundaries' );
}

/**
 * Returns the representatives matching the given postal code and belonging to
 * one of the given representative sets.
 *
 * @param string $postal_code
 *   A postal code
 * @param array $sets (optional)
 *   Machine names of representative sets, eg "house-of-commons"
 * @return array
 *   Matching representatives
 */
function represent_representatives_by_postal_code( $postal_code, $sets = array() ) {
  return represent_resources_by_postal_code( $postal_code, 'representatives', 'representative', $sets );
}

/**
 * Returns the boundaries containing the given postal code and belonging to one
 * one of the given boundary sets.
 *
 * @param string $postal_code
 *   A postal code
 * @param array $sets (optional)
 *   Machine names of resource sets, eg "federal-electoral-districts"
 * @return array
 *   Matching boundaries
 */
function represent_boundaries_by_postal_code( $postal_code, $sets = array() ) {
  return represent_resources_by_postal_code( $postal_code, 'boundaries', 'boundary', $sets );
}

/**
 * @param string $singular
 *   The singular resource name
 * @return array
 *   The available resource sets
 */
function represent_resource_sets( $singular ) {
  return represent_objects( "${singular}-sets/?limit=0" );
}

/**
 * @param string $set
 *   The machine name of a resource set, eg "house-of-commons" or
 *   "federal-electoral-districts"
 * @param string $plural
 *   The plural resource name
 * @return array
 *   The resources in the resource set
 */
function represent_resources_by_set( $set, $plural ) {
  return represent_objects( "${plural}/${set}/?limit=0" );
}

/**
 * @param string $postal_code
 *   A postal code
 * @param array $sets (optional)
 *   Machine names of resource sets, eg "house-of-commons" or
 *   "federal-electoral-districts"
 * @param string $plural
 *   The plural resource name
 * @param string $singular
 *   The singular resource name
 * @return array
 *   The matching resources
 */
function represent_resources_by_postal_code( $postal_code, $plural, $singular, $sets = array() ) {
  // Get the JSON response.
  $postal_code = represent_format_postal_code( $postal_code );
  $json = represent_send_request( "postcodes/${postal_code}/" );

  // Find the matching resources.
  $matches = array();
  if ( $json ) {
    $set_field = "${singular}_set_url";
    if ( ! is_array( $sets ) ) {
      $sets = array( $sets );
    }

    foreach ( array( "${plural}_centroid", "${plural}_concordance" ) as $field ) {
      if ( isset( $json->$field ) ) {
        foreach ( $json->$field as $match ) {
          $set = represent_get_machine_name( $match->related->$set_field );
          if ( empty( $sets ) || in_array( $set, $sets ) ) {
            $matches[$set][] = $match;
          }
        }
      }
    }
  }
  return $matches;
}

/**
 * @param string $path
 *    A path
 * @return array
 *    The resources in the response
 */
function represent_objects( $path ) {
  $json = represent_send_request( $path );
  if ( $json ) {
    return $json->objects;
  }
  return array();
}

/**
 * @param string $path
 *   A path
 * @return object
 *   The JSON as a PHP object, or FALSE if an error occurred
 */
function represent_send_request( $path ) {
  $cache = wp_cache_get( $path, 'represent' );
  if ( $cache ) {
    return $cache;
  }

  $response = wp_remote_get( "https://represent.opennorth.ca/$path" );
  if ( is_wp_error( $response ) || 200 != wp_remote_retrieve_response_code( $response ) ) {
    return FALSE;
  }

  $json = json_decode( wp_remote_retrieve_body( $response ) );
  wp_cache_set( $path, $json, 'represent', strtotime( '+1 week' ) );
  return $json;
}

/**
 * Formats a postal code as "A1A1A1", ie uppercase without spaces.
 *
 * @param string $postal_code
 *   A postal code
 * @return string
 *   A formatted postal code
 */
function represent_format_postal_code( $postal_code ) {
  return preg_replace( '/[^A-Z0-9]/', '', strtoupper( $postal_code ) );
}

/**
 * @param string $path
 *   A path
 * @return string
 *   The name of the resource in the path
 */
function represent_get_machine_name( $path ) {
  return preg_replace( '@^/[^/]+/([^/]+).+$@', '\1', $path );
}

/**
 * @param string $postal_code
 *   A postal code
 * @return string
 *   The matching province
 */
function represent_province_by_postal_code( $postal_code ) {
  $postal_code = represent_format_postal_code( $postal_code );

  if ( preg_match( '/\AA/', $postal_code ) ) {
    return 'NL';
  }
  elseif ( preg_match( '/\AB/', $postal_code ) ) {
    return 'NS';
  }
  elseif ( preg_match( '/\AC/', $postal_code ) ) {
    return 'PE';
  }
  elseif ( preg_match( '/\AE/', $postal_code ) ) {
    return 'NB';
  }
  elseif ( preg_match( '/\A[GHJ]/', $postal_code ) ) {
    return 'QC';
  }
  elseif ( preg_match( '/\A[KLMNP]/', $postal_code ) ) {
    return 'ON';
  }
  elseif ( preg_match( '/\AR/', $postal_code ) ) {
    return 'MB';
  }
  elseif ( preg_match( '/\AS/', $postal_code ) ) {
    return 'SK';
  }
  elseif ( preg_match( '/\AT/', $postal_code ) ) {
    return 'AB';
  }
  elseif ( preg_match( '/\AV/', $postal_code ) ) {
    return 'BC';
  }
  elseif ( preg_match( '/\AX0[ABC]/', $postal_code ) ) {
    return 'NU';
  }
  elseif ( preg_match( '/\AX0[EG]|\AX1A/', $postal_code ) ) {
    return 'NT';
  }
  elseif ( preg_match( '/\AY/', $postal_code ) ) {
    return 'YT';
  }
  return FALSE;
}
