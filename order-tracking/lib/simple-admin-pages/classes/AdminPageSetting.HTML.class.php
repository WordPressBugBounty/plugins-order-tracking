<?php

/**
 * Register and save an arbitrary HTML chunk in the admin menu
 *
 * This allows you to easily add in a dummy "setting" with any arbitrary HTML
 * code. It's good for displaying a link to documentation, upgrades or anything
 * else you can think of.
 *
 * Data in this field will not be saved or passed. It's purely for presenting
 * information.
 *
 * @since 1.0
 * @package Simple Admin Pages
 */

class sapAdminPageSettingHTML_2_7_4 extends sapAdminPageSetting_2_7_4 {

	public $html; // The HTML that should be displayed by this option
	public $sanitize_callback = 'sanitize_text_field';

	/**
	 * Display this setting
	 * @since 1.0
	 */
	public function display_setting() {

		?>

        <fieldset <?php $this->print_conditional_data(); ?>>
        	<?php
        		if ( is_callable( $this->html ) ) {
    				wp_kses_post( call_user_func( $this->html ) ?? '' ); 
				} else {
					echo wp_kses_post( $this->html );
				}
            ?>
        </fieldset>

        <?php

		$this->display_description();

	}

}
