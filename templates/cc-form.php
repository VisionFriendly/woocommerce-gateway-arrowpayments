<div>
    <p class="form-row form-row-wide">
        <label><?php _e('Credit Card Number', 'wc-arrowpayments'); ?></label>
        <input type="text" class="input-text" size="15" name="billing-cc-number" value="<?php echo ($prefill ? '4111111111111111' : ''); ?>"></input>
    </p>
    <p class="form-row form-row-first">
        <label><?php _e('Exp. (mm/yy)', 'wc-arrowpayments'); ?></label>
        <input type="text" class="input-text" size="4" name="billing-cc-exp" value="<?php echo ($prefill ? '11/17' : ''); ?>"></input>
    </p>
    <p class="form-row form-row-last">
        <label><?php _e('CVV', 'wc-arrowpayments'); ?></label>
        <input type="text" class="input-text" size="4" name="billing-cvv" value="<?php echo ($prefill ? '111' : ''); ?>"></input>
    </p>
</div>
<input type="submit" value="<?php _e('Confirm and pay', 'wc-arrowpayments'); ?>" class="submit buy button">