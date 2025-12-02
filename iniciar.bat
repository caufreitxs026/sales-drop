@echo off
TITLE SalesDrop Analytics Server
COLOR 0A

:: Configurações
SET PHP_PATH=C:\php\php.exe
SET PORT=8080

:: Verifica se o PHP existe
IF NOT EXIST "%PHP_PATH%" (
COLOR 0C
echo [ERRO] O PHP nao foi encontrado em %PHP_PATH%
echo Por favor, instale o PHP ou ajuste o caminho neste arquivo .bat
pause
exit
)

:: Inicia o navegador
echo Iniciando navegador...
start http://localhost:%PORT%

:: Inicia o servidor PHP
echo.
echo ========================================================
echo   SalesDrop Analytics - Servidor Rodando
echo   Acesse: http://localhost:%PORT%
echo ========================================================
echo.
echo   [!] NAO FECHE ESTA JANELA ENQUANTO USAR O SISTEMA
echo.

"%PHP_PATH%" -S localhost:%PORT% -d upload_max_filesize=100M -d post_max_size=100M -d max_execution_time=300

pause