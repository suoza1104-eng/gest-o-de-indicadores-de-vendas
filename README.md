# Meta Ads Manager + Atribuição Real

Projeto PHP pronto para subir no servidor.

## O que já vem pronto
- conexão PDO com o banco `prof2543_meta_ads_manager`
- conexão PDO com o banco fonte `prof2543_area_membros`
- tela administrativa em `admin/index.php`
- cadastro/edição da integração Meta
- teste de conexão
- sincronização manual da Meta
- sincronização manual da atribuição real
- cron para sincronização automática completa
- dashboard Meta
- dashboard real cruzando gasto, leads, vendas, receita, CAC, CPL e ROAS

## Passos antes de usar
1. Execute o SQL em `sql/create_tables.sql` dentro do banco `prof2543_meta_ads_manager`.
2. Suba os arquivos para o servidor.
3. Acesse `SEU_DOMINIO/PASTA_DO_PROJETO/admin/`.
4. Preencha App ID, App Secret, Access Token e Ad Account ID.
5. Salve a integração.
6. Clique em **Sincronizar Meta**.
7. Depois clique em **Sincronizar atribuição**.

## Como a atribuição está sendo feita
- leads lidos da tabela `users` do banco `prof2543_area_membros`
- vendas lidas da tabela `hotmart_sales`
- prioridade de match:
  1. `matched_user_id`
  2. telefone
  3. email
  4. nome
- modelos gerados:
  - `first_touch`
  - `last_touch`

## Mapeamento de UTM usado
- `utm_medium` -> campanha
- `utm_campaign` -> conjunto
- `utm_content` -> anúncio

## Cron sugerido
```bash
/usr/local/bin/php /home/USUARIO/public_html/PASTA_DO_PROJETO/cron/sync.php
```

## Segurança
No arquivo `config/config.php`, troque a chave `admin_api_key` antes de produção.
