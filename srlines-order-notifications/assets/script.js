jQuery(document).ready(function($) {
    // Save settings
    $('#wc-notifications-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        const btn = $(this).find('button[type="submit"]');
        const originalText = btn.text();
        
        btn.text('Saving...').prop('disabled', true);
        
        $.ajax({
            url: wcNotifications.ajax_url,
            method: 'POST',
            data: {
                action: 'wc_notifications_save_settings',
                nonce: wcNotifications.nonce,
                crmApiKey: $('#crm_api_key').val(),
                orderCreatedEnabled: $('#order_created_enabled').is(':checked'),
                orderCreatedTemplate: $('#order_created_template').val(),
                fulfillmentCreatedEnabled: $('#fulfillment_created_enabled').is(':checked'),
                fulfillmentCreatedTemplate: $('#fulfillment_created_template').val(),
                defaultPhone: $('#default_phone').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice('✅ Settings saved successfully!', 'success');
                } else {
                    showNotice('❌ Error: ' + response.data.message, 'error');
                }
            },
            error: function(xhr) {
                showNotice('❌ Failed to save settings. Please try again.', 'error');
                console.error('Save error:', xhr.responseText);
            },
            complete: function() {
                btn.text(originalText).prop('disabled', false);
            }
        });
    });

    // Test API key
    $('#test-api-key').on('click', function() {
        const apiKey = $('#crm_api_key').val();
        
        if (!apiKey) {
            showNotice('⚠️ Please enter an API key', 'warning');
            return;
        }

        const btn = $(this);
        const originalText = btn.text();
        
        btn.text('Testing...').prop('disabled', true);
        
        $.ajax({
            url: wcNotifications.ajax_url,
            method: 'POST',
            data: {
                action: 'wc_notifications_test_api_key',
                nonce: wcNotifications.nonce,
                apiKey: apiKey
            },
            success: function(response) {
                if (response.success) {
                    showNotice('✅ ' + response.data.message, 'success');
                } else {
                    showNotice('❌ ' + response.data.message, 'error');
                }
            },
            error: function(xhr) {
                showNotice('❌ Failed to test API key. Check connection.', 'error');
            },
            complete: function() {
                btn.text(originalText).prop('disabled', false);
            }
        });
    });

    // Process response
    $(document).on('click', '.process-response', function() {
        const btn = $(this);
        const id = btn.data('id');
        const orderId = btn.data('order');
        const action = btn.data('action');
        
        if (!confirm(`Process ${action} response for order #${orderId}?`)) {
            return;
        }

        const originalText = btn.text();
        btn.text('Processing...').prop('disabled', true);
        
        $.ajax({
            url: wcNotifications.ajax_url,
            method: 'POST',
            data: {
                action: 'wc_notifications_process_response',
                nonce: wcNotifications.nonce,
                response_id: id
            },
            success: function(response) {
                if (response.success) {
                    showNotice('✅ Order status updated successfully!', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice('❌ Error: ' + response.data.message, 'error');
                    btn.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                showNotice('❌ Failed to process response', 'error');
                btn.text(originalText).prop('disabled', false);
            }
        });
    });

    // Resend notification
    $(document).on('click', '.resend-notification', function() {
        const btn = $(this);
        const id = btn.data('id');
        const orderId = btn.data('order');
        
        if (!confirm(`Resend notification for order ${orderId}?`)) {
            return;
        }

        const originalText = btn.text();
        btn.text('Resending...').prop('disabled', true);
        
        $.ajax({
            url: wcNotifications.ajax_url,
            method: 'POST',
            data: {
                action: 'wc_notifications_resend_notification',
                nonce: wcNotifications.nonce,
                notification_id: id
            },
            success: function(response) {
                if (response.success) {
                    showNotice('✅ Notification resent successfully!', 'success');
                } else {
                    showNotice('❌ Error: ' + response.data.message, 'error');
                }
                btn.text(originalText).prop('disabled', false);
            },
            error: function() {
                showNotice('❌ Failed to resend notification', 'error');
                btn.text(originalText).prop('disabled', false);
            }
        });
    });

    // Show notice function
    function showNotice(message, type) {
        const types = {
            success: 'notice-success',
            error: 'notice-error',
            warning: 'notice-warning',
            info: 'notice-info'
        };
        
        const noticeClass = types[type] || 'notice-info';
        const notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').first().after(notice);
        
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        notice.find('button.notice-dismiss').on('click', function() {
            notice.remove();
        });
    }

    // Copy webhook URL
    $(document).on('click', '.copy-webhook-url', function() {
        const url = $(this).data('url');
        const temp = $('<input>');
        $('body').append(temp);
        temp.val(url).select();
        document.execCommand('copy');
        temp.remove();
        showNotice('📋 Webhook URL copied to clipboard!', 'success');
    });
});