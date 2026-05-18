<?php

/**
 * Register, display and save a text field setting in the admin menu
 *
 * @since 1.0
 * @package Simple Admin Pages
 */

class sapAdminPageSettingNumber_2_7_4 extends sapAdminPageSetting_2_7_4 {

	public $sanitize_callback = 'sanitize_text_field';

	/**
	 * Placeholder string for the input field
	 * @since 2.0
	 */
	public $placeholder = '';

	// The default value for the field when none has been set
	public $default;

	// The lowest value allowed
	public $min = null;
	
	// The highest value allowed
	public $max = null;

	// The number to increment/decrement by. Non-integers will allow floating point numbers for the input.
	public $step = null;

	/**
	 * Display this setting
	 * @since 1.0
	 */
	public function display_setting() {
		?>

		<fieldset <?php $this->print_conditional_data(); ?>>

			<input name="<?php echo esc_attr( $this->get_input_name() ); ?>" type="number" id="<?php echo esc_attr( $this->get_input_name() ); ?>" value="<?php echo esc_attr( $this->value ); ?>"<?php echo !empty( $this->placeholder ) ? ' placeholder="' . esc_attr( $this->placeholder ) . '"' : ''; ?><?php echo isset( $this->min ) ? ' min="' . esc_attr( $this->min ) . '"' : ''; ?><?php echo isset( $this->max ) ? ' max="' . esc_attr( $this->max ) . '"' : ''; ?><?php echo isset( $this->step ) ? ' step="' . esc_attr( $this->step ) . '"' : ''; ?> class="regular-text" <?php echo ( $this->disabled ? 'disabled' : ''); ?> />

			<?php $this->display_disabled(); ?>	
			
		</fieldset>
		
		<?php

		$this->display_description();

	}

}
