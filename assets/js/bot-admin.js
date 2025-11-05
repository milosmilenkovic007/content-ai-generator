jQuery(function($){
  // Fallback images picker
  var frame2;
  function refreshFallback(ids){
    var $list = $('#ai-bot-fallback-list');
    $list.empty();
    if(!ids){ return; }
    ids.split(',').filter(Boolean).forEach(function(id){
      $list.append('<div class="ai-bot-lib-item" data-id="'+id+'">#'+id+'</div>');
    });
  }
  $('#ai-bot-add-fallback-images').on('click', function(e){
    e.preventDefault();
    if(frame2){ frame2.open(); return; }
    frame2 = wp.media({ title: 'Select Fallback Images', button: { text: 'Use images' }, multiple: true });
    frame2.on('select', function(){
      var ids = $('#ai_bot_fallback_image_ids').val();
      var selection = frame2.state().get('selection');
      selection.each(function(att){
        var id = att.get('id');
        ids = ids ? (ids+','+id) : String(id);
      });
      $('#ai_bot_fallback_image_ids').val(ids);
      refreshFallback(ids);
    });
    frame2.open();
  });
  $('#ai-bot-clear-fallback-images').on('click', function(){
    $('#ai_bot_fallback_image_ids').val('');
    refreshFallback('');
  });
});