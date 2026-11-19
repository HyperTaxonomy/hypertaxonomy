<?php
/**
 * Options of term merges and conversions that other plugins share.
 *
 * Plugins that keep data on terms offer choices for it on the Merge Terms and Convert Terms
 * pages (htax_merge_terms_check, htax_convert_terms_check), and get the choices made as
 * $options in the merge and conversion actions, keyed by their section ID. For data that a
 * redirect stub, the target (or new term), or both could keep, these helpers build the same
 * four choices for every plugin, and resolve which one applies:
 *
 *  - stub: leave it on the redirect stubs. Needs stubs; the default with stubs.
 *  - move: move it to the target or new terms. The default without stubs.
 *  - copy: copy it there, and leave it on the stubs. Needs stubs.
 *  - drop: delete it.
 *
 * The section ID 'htax' is HyperTaxonomy's own. Its 'carry' value is the operation-wide
 * preference for this kind of data, for callers that run an operation without the page:
 * htax_carry_choice() uses it when a plugin's own field wasn't answered.
 *
 * @package HyperTaxonomy
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The values of the shared choice for data a stub, the target or both could keep.
 *
 * @since 1.0.0
 * @return string[]
 */
function htax_carry_values(): array {
    return array( 'stub', 'move', 'copy', 'drop' );
}

/**
 * A check step field offering the shared choice, for a plugin's htax_merge_terms_check or
 * htax_convert_terms_check section.
 *
 * @since 1.0.0
 *
 * @param array $args {
 *     @type string   $name         Field name: the key of the choice in $options[ section ].
 *     @type string   $label        Field label: what the data is.
 *     @type string   $target_name  Merge target's name, or '' for a conversion's new terms.
 *     @type bool     $movable      Whether the target or new terms can take the data. Without,
 *                                  only 'stub' and 'drop' are offered. Default true.
 *     @type string[] $descriptions Descriptions of the choices, by value (optional).
 * }
 * @return array Field.
 */
function htax_carry_field( array $args ): array {
    $target_name  = (string) ( $args['target_name'] ?? '' );
    $movable      = (bool) ( $args['movable'] ?? true );
    $descriptions = (array) ( $args['descriptions'] ?? array() );

    $choices = array(
        'stub' => array( __( 'Leave them on the redirect stubs', 'hypertaxonomy' ), 'required' ),
    );
    if ( $movable ) {
        $choices['move'] = array(
            '' !== $target_name
                /* translators: %s: target term name */
                ? sprintf( __( 'Move them to %s', 'hypertaxonomy' ), $target_name )
                : __( 'Move them to the new terms', 'hypertaxonomy' ),
            '',
        );
        $choices['copy'] = array(
            '' !== $target_name
                /* translators: %s: target term name */
                ? sprintf( __( 'Copy them to %s, and leave them on the redirect stubs', 'hypertaxonomy' ), $target_name )
                : __( 'Copy them to the new terms, and leave them on the redirect stubs', 'hypertaxonomy' ),
            'required',
        );
    }
    $choices['drop'] = array( __( 'Delete them', 'hypertaxonomy' ), '' );

    $list = array();
    foreach ( $choices as $value => $choice ) {
        $list[] = array(
            'value'       => $value,
            'label'       => $choice[0],
            'description' => (string) ( $descriptions[ $value ] ?? '' ),
            'stub'        => $choice[1],
        );
    }

    return array(
        'name'            => (string) $args['name'],
        'label'           => (string) $args['label'],
        'choices'         => $list,
        'default'         => 'stub',
        'default_no_stub' => $movable ? 'move' : 'drop',
    );
}

/**
 * The shared choice that applies to a term's data in a merge or conversion: the plugin's own
 * field, else the operation-wide preference ($options['htax']['carry']), else the default
 * ('stub' with a stub, 'move' without). It is then fitted to what is possible: without a stub,
 * 'stub' and 'copy' become 'move'; when the target or new term can't take the data, 'move' and
 * 'copy' become 'stub', or 'drop' without a stub.
 *
 * Call it per term: in a merge, a source whose stub couldn't be created has none.
 *
 * @since 1.0.0
 *
 * @param array  $options  The operation's options, as the merge and conversion actions get them.
 * @param string $section  The plugin's section ID.
 * @param string $field    The field's name.
 * @param bool   $has_stub Whether the term has a redirect stub.
 * @param bool   $movable  Whether the target or new term can take the data.
 * @return string 'stub', 'move', 'copy' or 'drop'.
 */
function htax_carry_choice( array $options, string $section, string $field, bool $has_stub, bool $movable = true ): string {
    $values = htax_carry_values();
    $choice = (string) ( $options[ $section ][ $field ] ?? '' );
    if ( ! in_array( $choice, $values, true ) ) {
        $choice = (string) ( $options['htax']['carry'] ?? '' );
    }
    if ( ! in_array( $choice, $values, true ) ) {
        $choice = $has_stub ? 'stub' : 'move';
    }
    if ( ! $has_stub && in_array( $choice, array( 'stub', 'copy' ), true ) ) {
        $choice = 'move';
    }
    if ( ! $movable && in_array( $choice, array( 'move', 'copy' ), true ) ) {
        $choice = $has_stub ? 'stub' : 'drop';
    }
    return $choice;
}
