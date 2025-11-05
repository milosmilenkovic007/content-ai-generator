jQuery(function($){
  function updateProgress(data){
    var $wrap = $('#ai-bot-progress'), $bar = $('#ai-bot-progress-bar'), $txt = $('#ai-bot-progress-text');
    if(!data){ return; }
    var pct = 0;
    if(data.total && data.step !== undefined){ pct = Math.round((data.step / data.total) * 100); }
    $wrap.show(); $txt.show();
    $bar.css('width', pct+'%');
    $txt.text(data.message || '');
  }

  function poll(botId, attempt){
    attempt = attempt || 0;
    $.get(AIBotGen.ajax, { action: 'ai_blog_generation_progress', bot_id: botId, nonce: AIBotGen.nonce })
      .done(function(res){
        if(!res || !res.success){ return; }
        var d = res.data || {};
        updateProgress(d);
        if(d.status === 'done' || d.status === 'error' || d.status === 'skipped'){ return; }
        setTimeout(function(){ poll(botId, attempt+1); }, 1500);
      });
  }

  $('#ai-bot-generate-now').on('click', function(){
    var botId = $(this).data('bot');
    $('#ai-bot-progress').show(); $('#ai-bot-progress-text').show().text(AIBotGen.i18n.starting);
    $.post(AIBotGen.ajax, { action: 'ai_blog_generate_now', bot_id: botId, nonce: AIBotGen.nonce })
      .done(function(res){
        if(!res || !res.success){ $('#ai-bot-progress-text').text(AIBotGen.i18n.error); return; }
        poll(botId, 0);
      })
      .fail(function(){ $('#ai-bot-progress-text').text(AIBotGen.i18n.error); });
  });
});