#!/bin/bash
# Forge Installation Script
#
# Server Files: /mnt/server
#
# Fixed version of the install script bundled with Pterodactyl 1.15.1:
#  - version keys are matched exactly ("1.21.1" no longer also matches 1.21.10 / 1.21.11),
#  - whether to link unix_args.txt or rename a jar is decided by what the installer
#    produced, so Minecraft 26.x (and anything newer) works instead of silently
#    leaving a server with no jar and no unix_args.txt,
#  - a broken result fails the install instead of reporting success.
apt update
apt install -y curl jq

if [[ ! -d /mnt/server ]]; then
  mkdir /mnt/server
fi

cd /mnt/server

# Remove spaces from the version number to avoid issues with curl
FORGE_VERSION="$(echo "$FORGE_VERSION" | tr -d ' ')"
MC_VERSION="$(echo "$MC_VERSION" | tr -d ' ')"
FILE_SITE=https://maven.minecraftforge.net/net/minecraftforge/forge/

if [[ -n ${FORGE_VERSION} ]]; then
  DOWNLOAD_LINK=${FILE_SITE}${FORGE_VERSION}/forge-${FORGE_VERSION}
  FORGE_JAR=forge-${FORGE_VERSION}*.jar
else
  JSON_DATA=$(curl -sSL https://files.minecraftforge.net/maven/net/minecraftforge/forge/promotions_slim.json)
  if [[ -z "${JSON_DATA}" ]]; then
    echo -e "Could not download the Forge promotions list. Exiting now"
    exit 1
  fi

  if [[ "${MC_VERSION}" == "latest" ]] || [[ "${MC_VERSION}" == "" ]]; then
    echo -e "getting latest version of forge."
    MC_VERSION=$(echo "${JSON_DATA}" | jq -r '.promos | del(."latest-1.7.10") | del(."1.7.10-latest-1.7.10") | to_entries[] | .key | select(endswith("-latest")) | split("-")[0]' | sort -t. -k 1,1n -k 2,2n -k 3,3n -k 4,4n | tail -1)
    BUILD_TYPE=latest
  fi

  if [[ "${BUILD_TYPE}" != "recommended" ]] && [[ "${BUILD_TYPE}" != "latest" ]]; then
    BUILD_TYPE=recommended
  fi

  echo -e "minecraft version: ${MC_VERSION}"
  echo -e "build type: ${BUILD_TYPE}"

  ## Exact key match. The original used "contains", which made 1.21.1 ambiguous once 1.21.10 and 1.21.11 existed.
  VERSION_KEY=$(echo "${JSON_DATA}" | jq -r --arg KEY "${MC_VERSION}-${BUILD_TYPE}" '.promos | to_entries[] | .key | select(. == $KEY)')

  if [[ -z "${VERSION_KEY}" ]] && [[ "${BUILD_TYPE}" == "recommended" ]]; then
    echo -e "dropping back to latest from recommended due to there not being a recommended version of forge for the mc version requested."
    VERSION_KEY=$(echo "${JSON_DATA}" | jq -r --arg KEY "${MC_VERSION}-latest" '.promos | to_entries[] | .key | select(. == $KEY)')
  fi

  if [[ -z "${VERSION_KEY}" ]] || [[ "${VERSION_KEY}" == "null" ]]; then
    echo -e "The install failed because there is no valid version of forge for the version of minecraft selected (${MC_VERSION})."
    exit 1
  fi

  FORGE_VERSION=$(echo "${JSON_DATA}" | jq -r --arg VERSION_KEY "$VERSION_KEY" '.promos | .[$VERSION_KEY]')

  if [[ "${MC_VERSION}" == "1.7.10" ]] || [[ "${MC_VERSION}" == "1.8.9" ]]; then
    DOWNLOAD_LINK=${FILE_SITE}${MC_VERSION}-${FORGE_VERSION}-${MC_VERSION}/forge-${MC_VERSION}-${FORGE_VERSION}-${MC_VERSION}
    FORGE_JAR=forge-${MC_VERSION}-${FORGE_VERSION}-${MC_VERSION}.jar
    if [[ "${MC_VERSION}" == "1.7.10" ]]; then
      FORGE_JAR=forge-${MC_VERSION}-${FORGE_VERSION}-${MC_VERSION}-universal.jar
    fi
  else
    DOWNLOAD_LINK=${FILE_SITE}${MC_VERSION}-${FORGE_VERSION}/forge-${MC_VERSION}-${FORGE_VERSION}
    FORGE_JAR=forge-${MC_VERSION}-${FORGE_VERSION}.jar
  fi
fi

#Adding .jar when not ending with SERVER_JARFILE
if [[ ! $SERVER_JARFILE = *\.jar ]]; then
  SERVER_JARFILE="$SERVER_JARFILE.jar"
fi

#Downloading jars
echo -e "Downloading forge version ${FORGE_VERSION}"
echo -e "Download link is ${DOWNLOAD_LINK}"

if [[ -n "${DOWNLOAD_LINK}" ]]; then
  if curl --output /dev/null --silent --head --fail ${DOWNLOAD_LINK}-installer.jar; then
    echo -e "installer jar download link is valid."
  else
    echo -e "link is invalid. Exiting now"
    exit 2
  fi
else
  echo -e "no download link provided. Exiting now"
  exit 3
fi

curl -s -o installer.jar -sS ${DOWNLOAD_LINK}-installer.jar

#Checking if downloaded jars exist
if [[ ! -f ./installer.jar ]]; then
  echo "!!! Error downloading forge version ${FORGE_VERSION} !!!"
  exit 2
fi

# Delete args to support downgrading/upgrading
rm -rf libraries/net/minecraftforge/forge
rm -f unix_args.txt

#Installing server
echo -e "Installing forge server.\n"
java -jar installer.jar --installServer || { echo -e "\nInstall failed using Forge version ${FORGE_VERSION} and Minecraft version ${MC_VERSION}.\nShould you be using unlimited memory value of 0, make sure to increase the default install resource limits in the Wings config or specify exact allocated memory in the server Build Configuration instead of 0! \nOtherwise, the Forge installer will not have enough memory."; exit 4; }

# Decide by what the installer produced, not by the version number. Forge 1.17+ (and 26.x) ship
# their launch arguments in libraries/net/minecraftforge/forge/<version>/unix_args.txt and no
# runnable jar in the server root; older versions produce a jar that the startup command runs.
ARGS_FILE=$(ls libraries/net/minecraftforge/forge/*/unix_args.txt 2>/dev/null | head -n 1)
if [[ -n "${ARGS_FILE}" ]]; then
  echo -e "Detected a Forge version that uses unix_args.txt (1.17 and newer). Setting up forge unix args."
  ln -sf "${ARGS_FILE}" unix_args.txt
else
  JAR=$(ls ${FORGE_JAR} 2>/dev/null | grep -v -- '-installer' | grep -v -- '-shim' | head -n 1)
  if [[ -z "${JAR}" ]]; then
    echo -e "The installer finished but produced neither unix_args.txt nor a Forge server jar. Failing the install so this is visible."
    exit 5
  fi
  echo -e "Renaming ${JAR} to ${SERVER_JARFILE}"
  mv "${JAR}" "${SERVER_JARFILE}"
fi

echo -e "Deleting installer.jar file.\n"
rm -rf installer.jar installer.jar.log
echo -e "Installation process is completed"
