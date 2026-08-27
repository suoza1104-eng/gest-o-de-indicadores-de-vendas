# Meta Ads Manager Pro

Sistema PHP/MySQL para acompanhar gasto de trafego, vendas Hotmart, atribuicao por UTM e KPIs comerciais.

## Modulos do painel

- Indicadores: cards, graficos e KPIs do periodo filtrado.
- Campanhas: ranking por campanha, conjunto e anuncio, vendas atribuidas e pendencias de atribuicao.
- Configuracoes: integracao Meta, sincronizacoes e historico dos jobs.

## Preparacao para Git

O arquivo real `config/config.php` nao deve ir para o repositorio porque contem credenciais. Use `config/config.example.php` como base no servidor.

```bash
git init
git add .
git commit -m "Versao profissional inicial do Meta Ads Manager"
```

Depois crie um repositorio vazio no GitHub/GitLab/Bitbucket e conecte:

```bash
git remote add origin URL_DO_REPOSITORIO
git branch -M main
git push -u origin main
```

## Deploy futuro

Para publicar em `www.professoremersonleite.site`, envie os arquivos do projeto para a pasta publica do dominio, copie `config/config.example.php` para `config/config.php`, preencha as credenciais reais, execute o SQL necessario e configure o cron:

```bash
/usr/local/bin/php /CAMINHO_DO_SITE/cron/sync.php
```

