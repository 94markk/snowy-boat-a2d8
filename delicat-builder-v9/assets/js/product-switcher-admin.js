(function($){'use strict';
  var $rows=$('#dpsr-admin-rows'); if(!$rows.length)return;
  var max=30;
  function reindex(){
    $rows.children('.dpsr-admin-row').each(function(i){
      this.setAttribute('data-index',String(i));
      $(this).find('[name]').each(function(){this.name=this.name.replace(/dpsr\[options\]\[[^\]]+\]/,'dpsr[options]['+i+']');});
    });
  }
  function addRow(data){
    if($rows.children().length>=max)return;
    var tpl=$('#tmpl-dpsr-row').html().replace(/999999/g,String($rows.children().length));
    var $row=$(tpl);
    if(data){
      $row.find('[name$="[label]"]').val(data.label||'');
      $row.find('[name$="[product_id]"]').val(data.product_id||'');
      $row.find('[name$="[icon]"]').val(data.icon||'');
    }
    $rows.append($row); reindex();
  }
  $rows.sortable({handle:'.dpsr-drag',axis:'y',update:reindex});
  $('#dpsr-add-option').on('click',function(){addRow();});
  $rows.on('click','.dpsr-remove',function(){
    if($rows.children().length===1){$(this).closest('.dpsr-admin-row').find('input').val('');return;}
    $(this).closest('.dpsr-admin-row').remove();reindex();
  });
  $('#dpsr-free-fire').on('click',function(){
    var presets=[
      {label:'Free Fire (AMÉRIQUE)',icon:'🇺🇸'},
      {label:'Free Fire (LATAM)',icon:'🌎'},
      {label:'Free Fire (EUROPE)',icon:'🇪🇺'},
      {label:'Free Fire (MENA)',icon:'🌍'},
      {label:'Free Fire (ASIE)',icon:'🌏'},
      {label:'Free Fire (AFRIQUE)',icon:'🌍'}
    ];
    $rows.empty(); presets.forEach(addRow); reindex();
    $('select[name="dpsr[type]"]').val('regions');
    var $title=$('input[name="dpsr[title]"]'); if(!$title.val()||$title.val()==='Choisissez votre région')$title.val('Choisissez votre région');
  });
})(jQuery);
