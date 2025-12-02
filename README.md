# SalesDrop Analytics Dashboard

Sistema full-stack para auditoria automatizada de pedidos de venda e
análise de performance logística.\
Processa planilhas brutas de vendas, cruza informações com o banco de
dados ERP (SQL Server) e aplica regras de negócio configuráveis para
validar se os pedidos atingiram o valor mínimo exigido por canal (FAD).

## Sobre o Projeto

O SalesDrop foi criado para substituir processos manuales e repetitivos
de conferência de pedidos, garantindo agilidade, precisão e
rastreabilidade dos dados.\
A aplicação combina extração, validação e visualização em um único fluxo
integrado.

## Principais Funcionalidades

### Processamento de Dados Híbrido

-   Interface em PHP para gestão e upload\
-   Motor de processamento em Python (Pandas) para análise de alto
    desempenho\
-   Suporte a grandes volumes de dados

### Duplo Cenário de Validação

-   Compara a classificação do cliente da planilha importada com o
    cadastro real no SQL Server\
-   Permite alternar a visão no dashboard para análises distintas

### Auditoria Automatizada

-   Financeira: identifica divergências entre valores calculados e
    valores esperados (gabarito)\
-   Cadastral: identifica discrepâncias entre canal da planilha e canal
    do ERP

### Regras de Negócio Dinâmicas

-   Interface própria para configurar valores mínimos por canal\
-   Armazenamento leve em JSON

### Dashboards Interativos

-   KPIs por vendedor, cliente e canal\
-   Drill-down detalhado\
-   Paginação para performance

### Exportação de Relatórios

-   Geração de CSVs e TXTs detalhados para conferência e auditoria\
-   Exportação por visão específica

## Tecnologias Utilizadas

### Backend

-   Python 3.x (Pandas, PyODBC, JSON)

### Frontend / Server

-   PHP 8.x

### Banco de Dados

-   SQL Server (integração via ODBC)

### Interface

-   HTML5\
-   CSS3 (responsivo)\
-   Chart.js

## Como Executar

### Pré-requisitos

-   Python 3 instalado\
-   PHP instalado\
-   Driver ODBC 17 for SQL Server

### Instalação

Clone o repositório:

    git clone https://github.com/seu-usuario/sales-drop-analytics.git

Instale as dependências Python:

    pip install -r requirements.txt

Inicie o servidor PHP embutido:

    php -S localhost:8000

Acesse no navegador:

    http://localhost:8000

## Estrutura de Arquivos

    sales-drop-analytics/
    │
    ├── index.php               # Dashboard principal e upload
    ├── processamento.py        # ETL e regras de negócio
    ├── config.php              # Interface de gestão das regras FAD
    ├── config_fads.json        # Valores mínimos por canal
    ├── style.css               # Estilos globais
    └── requirements.txt        # Dependências Python

## Contato

LinkedIn: https://www.linkedin.com/in/cauafreitas\
GitHub: https://github.com/caufreitxs026

## Licença

Este projeto está sob a licença MIT.\
Sinta-se à vontade para usar, adaptar e evoluir.
