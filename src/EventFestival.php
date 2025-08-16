<?php

use Tribe\Events\Filterbar\Views\V2\Filters\Context_Filter;

class EventFestival extends \Tribe__Events__Filterbar__Filter
{
  // Use the trait required for filters to correctly work with Views V2 code.
  use Context_Filter;

  public $type = 'select';

  public function get_admin_form()
  {
    $title = $this->get_title_field();
    $type  = $this->get_multichoice_type_field();

    return $title . $type;
  }

  protected function get_values()
  {
    $terms = [];

    $args = [
      'taxonomy'   => 'event_festival',
      'orderby'    => 'name',
      'order'      => 'ASC',
      'number'     => 200,
      'hide_empty' => true,
    ];

    /**
     * Filter the args of displaying categories.
     *
     * @since 5.5.8
     *
     * @param array $args   The arguments passed to the `get_terms()` query when filtering for categories.
     * @param self  $filter The instance of the filter that we are filtering the args for.
     */
    $args = (array) apply_filters('tec_events_filter_filters_category_get_terms_args', $args, $this);

    // Load all available event categories.
    $source = get_terms($args);

    if (empty($source) || is_wp_error($source)) {
      return [];
    }

    // Preprocess the terms.
    foreach ($source as $term) {
      $terms[(int) $term->term_id] = $term;
      $term->parent                  = (int) $term->parent;
      $term->depth                   = 0;
      $term->children                = [];
    }

    // Initially copy the source list of terms to our ordered list.
    $ordered_terms = $terms;

    // Re-order!
    foreach ($terms as $id => $term) {
      // Skip root elements.
      if (0 === $term->parent) {
        continue;
      }

      // Reposition child terms within the ordered terms list.
      unset($ordered_terms[$id]);
      $term->depth                             = $this->get_depth($term);
      $terms[$term->parent]->children[$id] = $term;
    }

    // Finally flatten out and return.
    return $this->flattened_term_list($ordered_terms);
  }

  /**
   * Get Term Depth.
   *
   * @since 4.5
   *
   * @param     $term
   * @param int $level
   *
   * @return int
   */
  protected function get_depth($term, $level = 0)
  {
    if (! $term instanceof WP_Term) {
      return 0;
    }

    if (0 == $term->parent) {
      return $level;
    } else {
      ++$level;
      $term = get_term_by('id', $term->parent, 'event_festival');

      return $this->get_depth($term, $level);
    }
  }

  /**
   * Flatten out the hierarchical list of event categories into a single list of values,
   * applying formatting (non-breaking spaces) to help indicate the depth of each nested
   * item.
   *
   * @param array $term_items
   * @param array $existing_list
   *
   * @return array
   */
  protected function flattened_term_list(array $term_items, array $existing_list = null)
  {
    // Pull in the existing list when called recursively.
    $flat_list = is_array($existing_list) ? $existing_list : [];

    // Add each item - including nested items - to the flattened list.
    foreach ($term_items as $term) {

      $has_child        = ! empty($term->children) ? ' has-child closed' : '';
      $parent_child_cat = '';
      if (! $term->parent && $has_child) {
        $parent_child_cat = ' parent-' . absint($term->term_id);
      } elseif ($term->parent && $has_child) {
        $parent_child_cat = ' parent-' . absint($term->term_id) . ' child-' . absint($term->parent);
      } elseif ($term->parent && ! $has_child) {
        $parent_child_cat = ' child-' . absint($term->parent);
      }

      $flat_list[] = [
        'name'  => $term->name,
        'depth' => $term->depth,
        'value' => $term->term_id,
        'data'  => ['slug' => $term->slug],
        'class' =>
        esc_html($this->set_category_class($term->depth)) .
          'tribe-events-category-' . $term->slug .
          $parent_child_cat .
          $has_child,
      ];

      if (! empty($term->children)) {
        $child_items = $this->flattened_term_list($term->children, $existing_list);
        $flat_list   = array_merge($flat_list, $child_items);
      }
    }

    return $flat_list;
  }

  /**
   * Return class based on dept of the event category.
   *
   * @param $depth
   *
   * @return bool|string
   */
  protected function set_category_class($depth)
  {

    $class = 'tribe-parent-cat ';

    if (1 == $depth) {
      $class = 'tribe-child-cat ';
    } elseif (1 <= $depth) {
      $class = 'tribe-grandchild-cat tribe-depth-' . $depth . ' ';
    }

    /**
     * Filter the event category class based on the term depth for the Filter Bar.
     *
     * @param string $class class as a string
     * @param int    $depth numeric value of the depth, parent is 0
     */
    $class = apply_filters('tribe_events_filter_event_category_display_class', $class, $depth);

    return $class;
  }

  /**
   * This method will only be called when the user has applied the filter (during the
   * tribe_events_pre_get_posts action) and sets up the taxonomy query, respecting any
   * other taxonomy queries that might already have been setup (whether by The Events
   * Calendar, another plugin or some custom code, etc).
   *
   * @see Tribe__Events__Filterbar__Filter::pre_get_posts()
   *
   * @param WP_Query $query
   */
  protected function pre_get_posts(WP_Query $query)
  {
    // Only modify queries for tribe_events post type
    if ($query->get('post_type') !== 'tribe_events' || empty($this->currentValue)) {
      return;
    }

    $existing_rules = (array) $query->get('tax_query');
    $values = (array) $this->currentValue;

    // Handle select mode with child terms
    if ('select' === $this->type) {
      $categories = get_categories([
        'taxonomy' => 'event_festival',
        'child_of' => current($values),
      ]);
      if (!empty($categories)) {
        foreach ($categories as $category) {
          $values[] = $category->term_id;
        }
      }
    } elseif ('multiselect' === $this->type) {
      $values = array_filter(Arr::list_to_array($values));
    }

    $new_rule = [
      'taxonomy' => 'event_festival',
      'operator' => 'IN',
      'terms' => array_map('absint', $values),
    ];

    $relationship = apply_filters('tribe_events_filter_taxonomy_relationship', 'AND');
    $nest = apply_filters('tribe_events_filter_nest_taxonomy_queries', version_compare($GLOBALS['wp_version'], '4.1', '>='));

    if ($nest) {
      $tax_query = array_replace_recursive($existing_rules, [__CLASS__ => [$new_rule]]);
      if (!empty($relationship)) {
        $tax_query[__CLASS__]['relation'] = $relationship;
      }
    } else {
      $tax_query = [];
      $append = true;

      foreach ($existing_rules as $existing_rule_key => $existing_rule_value) {
        if ($existing_rule_value === '') {
          continue;
        }
        if (is_int($existing_rule_key)) {
          $tax_query[] = $existing_rule_value;
        } else {
          $tax_query[$existing_rule_key] = $existing_rule_value;
        }
        if (is_array($existing_rule_value) && $existing_rule_value == $new_rule) {
          $append = false;
          break;
        }
      }
      if ($append) {
        $tax_query[] = $new_rule;
      }
      if (!empty($relationship)) {
        $tax_query['relation'] = $relationship;
      }
    }

    // Ensure compatibility with TEC's event query
    $query->set('tax_query', $tax_query);

    // Force cache invalidation for custom filter
    $query->set('tribe_suppress_transient', true);
  }
}
