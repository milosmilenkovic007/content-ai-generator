jQuery(function($){
  var frame;
  var state = { paged: 1, perPage: 40, hasMore: false };

  function renderItems(items, append){
    var $grid = $('#ai-media-grid');
    var $empty = $('#ai-media-empty');
    if(!append){ $grid.empty(); $empty.hide(); }
    if(items.length === 0 && !append){ $empty.text(AIBlogMedia.i18n.loadedNone).show(); }
    items.forEach(function(it){
      var $item = $('<div/>', { 'class':'ai-media-item', 'data-id': it.id });
      var $img = $('<img/>', { src: it.src || '', alt: it.alt || '' });
      var $rm = $('<button/>', { 'class':'ai-media-remove', text: AIBlogMedia.i18n.remove, 'aria-label': 'Remove image' });
      $rm.on('click', function(ev){
        ev.preventDefault();
        var termId = $('#ai-media-term').val();
        $.post(AIBlogMedia.ajax, { action: 'ai_blog_remove_image', nonce: AIBlogMedia.nonce, id: it.id, term_id: termId })
          .done(function(){ $item.remove(); if($grid.children().length===0){ $empty.text(AIBlogMedia.i18n.loadedNone).show(); } })
          .fail(function(){ alert('Error removing image.'); });
      });
      $item.append($img).append($rm).appendTo($grid);
    });
  }

  function updateLoadMore(){
    var $btn = $('#ai-media-load-more');
    if(state.hasMore){
      $btn.text(AIBlogMedia.i18n.loadMore).show();
    } else {
      $btn.hide();
    }
  }

  function loadGrid(append){
    var termId = $('#ai-media-term').val();
    $('#ai-media-remove-all').prop('disabled', !termId);
    if(!termId){
      renderItems([], false);
      updateLoadMore();
      return;
    }
    $.get(AIBlogMedia.ajax, { action: 'ai_blog_list_images', nonce: AIBlogMedia.nonce, term_id: termId, paged: state.paged, per_page: state.perPage })
      .done(function(res){
        if(!res || !res.success){ return; }
        var items = res.data.items || [];
        state.hasMore = !!res.data.has_more;
        renderItems(items, !!append);
        updateLoadMore();
      });
  }

  $('#ai-media-term').on('change', function(){ state.paged = 1; loadGrid(false); });

  $('#ai-media-load-more').on('click', function(e){
    e.preventDefault();
    state.paged += 1;
    loadGrid(true);
  });

  $('#ai-media-remove-all').on('click', function(e){
    e.preventDefault();
    var termId = $('#ai-media-term').val();
    if(!termId){ return; }
    if(!confirm(AIBlogMedia.i18n.removeAllConfirm)){ return; }
    $.post(AIBlogMedia.ajax, { action:'ai_blog_remove_all_images', nonce: AIBlogMedia.nonce, term_id: termId })
      .done(function(){ alert(AIBlogMedia.i18n.removeAllDone); state.paged = 1; loadGrid(false); })
      .fail(function(){ alert('Error removing images.'); });
  });

  $('#ai-media-add').on('click', function(e){
    e.preventDefault();
    var termId = $('#ai-media-term').val();
    if(!termId){ alert(AIBlogMedia.i18n.selectCategory); return; }
    if(!frame){
      frame = wp.media({ title: 'Select images', button: { text: 'Assign to category' }, multiple: true, library: { type: 'image' } });
      frame.on('select', function(){
        var ids = [];
        frame.state().get('selection').each(function(att){ ids.push(att.get('id')); });
        var currentTermId = $('#ai-media-term').val();
        $.post(AIBlogMedia.ajax, { action:'ai_blog_attach_images', nonce: AIBlogMedia.nonce, ids: ids, term_id: currentTermId })
          .done(function(){ alert(AIBlogMedia.i18n.assigned); state.paged = 1; loadGrid(false); })
          .fail(function(){ alert(AIBlogMedia.i18n.assignError); });
      });
    }
    frame.open();
  });

  // If a category is preselected (e.g., via back/refresh), load it
  if($('#ai-media-term').val()){ state.paged = 1; loadGrid(false); }
});