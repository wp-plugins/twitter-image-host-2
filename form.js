jQuery(function() {
   jQuery('#message').keyup(function() {
      var count = jQuery('#character-count');
      var available = available_characters - this.value.length;
      if ( available < 0 ) {
         count.addClass('illegal');
      } else {
         count.removeClass('illegal');
      }
      count.html(available);
   });
});