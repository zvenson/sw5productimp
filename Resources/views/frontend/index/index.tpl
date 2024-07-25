{extends file='parent:frontend/index/index.tpl'}

{block name="frontend_index_header_javascript_data"}
  {$controllerData['pimp'] = {url controller=pimp action=index}}

  {$smarty.block.parent}
{/block}
