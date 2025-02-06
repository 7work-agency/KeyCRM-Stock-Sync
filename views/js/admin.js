/**
 * KeyCRM Stock Module Admin JavaScript
 */
$(document).ready(function() {
    // Add copy to clipboard functionality for cron command
    if($('.keycrm-settings pre').length) {
        var cronCommand = $('.keycrm-settings pre').text();
        $('.keycrm-settings pre').click(function() {
            var $temp = $("<input>");
            $("body").append($temp);
            $temp.val(cronCommand).select();
            document.execCommand("copy");
            $temp.remove();
            
            // Show copied notification
            var $alert = $('<div class="alert alert-success">').text('Command copied to clipboard!');
            $('.keycrm-settings').prepend($alert);
            setTimeout(function() {
                $alert.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        });
    }
});
