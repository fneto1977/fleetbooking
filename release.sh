#!/bin/bash

# ==============================================================================
# Script de Automação de Release - Plugin FleetBooking para GLPI
# ==============================================================================

PLUGIN_DIR="fleetbooking"
SETUP_FILE="setup.php"

echo "================================================="
echo "🚀 Iniciando processo de release do FleetBooking"
echo "================================================="

# Verifica se estamos rodando dentro da pasta correta
if [ ! -f "$SETUP_FILE" ]; then
    echo "❌ Erro: O arquivo setup.php não foi encontrado."
    echo "👉 Certifique-se de executar este script DENTRO da pasta 'fleetbooking'."
    exit 1
fi

# Extrai a versão dinamicamente do setup.php
VERSION=$(grep -oE "define\('PLUGIN_FLEETBOOKING_VERSION', '[0-9]+\.[0-9]+\.[0-9]+'\);" $SETUP_FILE | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")

if [ -z "$VERSION" ]; then
    echo "❌ Erro: Não foi possível detectar a versão no setup.php."
    exit 1
fi

echo "🏷️  Versão detectada: $VERSION"

# 1. Enviar para o GitHub
echo ""
echo "📦 Atualizando repositório Git..."
git add .
read -p "Digite a mensagem do commit (ou aperte Enter para usar 'Release v$VERSION'): " COMMIT_MSG
COMMIT_MSG=${COMMIT_MSG:-"Release v$VERSION"}

git commit -m "$COMMIT_MSG"

echo "⏳ Fazendo push para o GitHub..."
git push
if [ $? -ne 0 ]; then
    echo "⚠️  Aviso: Falha ao fazer o push para o GitHub. (Verifique se a branch remota está configurada)."
else
    echo "✅ Push concluído com sucesso!"
fi

# 2. Gerar arquivo ZIP para o GLPI
ZIP_NAME="fleetbooking-${VERSION}.zip"

echo ""
echo "🗜️  Gerando arquivo ZIP para distribuição: $ZIP_NAME..."

# Vai para a pasta pai (Plugin GLPI) para empacotar a pasta com o nome correto
cd ..

# Remove a versão anterior do zip, se existir
rm -f "$ZIP_NAME"

# Cria o zip ignorando o .git, o próprio script de release e os arquivos ocultos do Mac
zip -r "$ZIP_NAME" $PLUGIN_DIR -x "$PLUGIN_DIR/.git/*" -x "$PLUGIN_DIR/release.sh" -x "*/.DS_Store" -q

if [ ! -f "$ZIP_NAME" ]; then
    echo "❌ Erro ao gerar o arquivo ZIP."
    exit 1
fi

echo "✅ Arquivo ZIP gerado com sucesso em: $(pwd)/$ZIP_NAME"

# 3. Criar Release no GitHub e Anexar Asset
echo ""
echo "🚀 Criando Release no GitHub e anexando o arquivo ZIP..."

# Volta para a pasta do plugin porque os comandos 'gh' e 'git' dependem do diretório do repositório
cd "$PLUGIN_DIR"

if command -v gh &> /dev/null; then
    # Confere se está logado no GitHub CLI
    gh auth status &> /dev/null
    if [ $? -ne 0 ]; then
        echo "⚠️  Você não está autenticado no GitHub CLI (gh). A release não pode ser criada automaticamente."
        echo "💡 Para resolver, abra o terminal e rode: gh auth login"
        echo "🎉 O ZIP está pronto na pasta anterior para envio manual."
        exit 0
    fi
    
    TAG_NAME="v$VERSION"
    echo "Enviando $ZIP_NAME para o GitHub..."
    
    # Executa a criação da release pelo CLI
    # Isso vai criar a Tag, o corpo da Release e fazer o upload do arquivo ZIP como um Asset
    gh release create "$TAG_NAME" "../$ZIP_NAME" --title "FleetBooking $TAG_NAME" --notes "Release versão $VERSION"
    
    if [ $? -eq 0 ]; then
        echo "🎉 SUCESSO! Release $TAG_NAME publicada no GitHub com o plugin em anexo!"
    else
        echo "❌ Ocorreu um erro ao tentar criar a release pelo GitHub CLI."
    fi
else
    echo "⚠️  Aviso: O GitHub CLI (gh) não está instalado no seu sistema."
    echo "🎉 Processo local finalizado! O ZIP está pronto para ser enviado manualmente na aba 'Releases' do GitHub."
fi
