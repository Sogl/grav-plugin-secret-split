Русский | [English](README.md)

# Плагин Secret Split

Плагин для хранения выбранных секретов плагинов вне обычных YAML-конфигов Grav, при этом обычные настройки продолжают жить в стандартных файлах конфигурации.

![Интерфейс Secret Split](assets/interface.jpg)

## Что делает

- Загружает секреты из private storage-файлов:
  - базовое хранилище по умолчанию `user/secrets.yaml`
  - environment-хранилище по умолчанию `user/secrets.<environment>.yaml`
- Подмешивает их в runtime config Grav.
- Перехватывает сохранение protected fields из админки.
- Пишет защищённые поля в private secret files вместо обычного plugin config YAML.
- Удаляет защищённые ключи из plugin config после save.

## Зачем

Схема нужна, если вы хотите одновременно:

- хранить обычные настройки плагинов в git
- держать реальные секреты вне git

Типичные примеры:

- OAuth client secret
- SMTP password
- Algolia API keys

## Почему не `.env`, `setup.php` и не server variables?

В Grav уже есть способы подмешивать конфигурацию через `setup.php` и server-side environment variables:

- https://learn.getgrav.org/17/advanced/multisite-setup#server-based-configuration-overrides

Есть и плагины, идущие по пути environment variables (.env файлы), например:

- https://github.com/getgrav/grav-plugin-dotenv

Это нормальные подходы, но для этой задачи у них есть заметные ограничения:

- на shared hosting управление server-level environment часто недоступно или неудобно
- `.env`, `setup.php` и server overrides не интегрируются в обычный Admin и Flex save flow

`Secret Split` закрывает эту нишу: выбранные секреты выносятся из tracked YAML-конфигов плагинов в private storage-файлы, но обычное редактирование и сохранение продолжают работать через админку.

## Установка

### Ручная установка (сейчас)

1. Скачайте ZIP-архив этого репозитория.
2. Распакуйте его в `user/plugins/`.
3. Убедитесь, что итоговая директория плагина выглядит так:

```text
user/plugins/secret-split
```

### Установка через GPM (после публикации)

Когда плагин будет опубликован в официальном репозитории Grav plugins, его можно будет установить так:

```bash
bin/gpm install secret-split
```

## Модель хранения

Базовый private storage по умолчанию:

```yaml
user/secrets.yaml
```

Environment override по умолчанию:

```yaml
user/secrets.<environment>.yaml
```

Примеры:

- `user/secrets.yaml`
- `user/secrets.localhost.yaml`
- `user/secrets.example.com.yaml`

Оба пути настраиваются в конфиге Secret Split:

- `base_storage_file`
- `environment_storage_pattern`

Поведение специально приближено к Grav layering:

- значения из базового plugin config пишутся в базовый `user/secrets.yaml`
- значения из текущего env plugin config пишутся в `user/secrets.<environment>.yaml`
- возврат значений обратно в config восстанавливает их в соответствующий base или текущий env config layer
- secret-файлы автоматически создаются заново, когда соответствующий base или текущий env layer снова пишет секреты
- если имя Grav environment пустое или не определено, env-specific storage отключается вместо создания `secrets.unknown.yaml`

Конфиг самого Secret Split лежит в:

```yaml
user/config/plugins/secret-split.yaml
```

В этом файле хранится список защищаемых полей и настройки плагина.
Сами защищённые значения живут в настроенных private storage-файлах, которые по умолчанию равны `user/secrets.yaml` и `user/secrets.<environment>.yaml`.

## UI в админке

В конфиге плагина protected fields задаются группами по плагинам:

- выбирается плагин
- внутри выбирается одно или несколько полей этого плагина
- сам `secret-split` намеренно исключён из списка, чтобы плагин не пытался защищать собственные управляющие поля

Названия полей собираются из blueprint’ов плагинов и показываются так, как они названы в админке.
Сразу после выбора поля админка также показывает его текущий статус и то, где сейчас хранится значение.

## Password-подобные поля

Для password-подобных полей семантика берется автоматически из blueprint-поля Grav. Пустое значение для таких полей означает:

- пусто = оставить текущий секрет как есть
- не пусто = заменить секрет

Это нужно для полей вроде:

- `plugins.email.mailer.smtp.password`

потому что `password`-поля в Grav Admin по умолчанию всегда рендерятся пустыми.

Для обычных non-password protected полей явный пустой submit по-прежнему удаляет сохраненный secret автоматически.

## Удаление protected fields

Если поле удалить из `protected_fields`, то удаляются сразу две вещи:

- сама запись поля из конфига `secret-split`
- сохраненное значение секрета из `secrets.yaml` / `secrets.<env>.yaml`

## Действия в overview админки

В overview админки есть два отложенных действия:

- `Перенести в <secrets-файл>` подготавливает миграцию в private storage
- `Перенести в config` подготавливает возврат сохраненных значений обратно в tracked plugin config YAML

После нажатия на любое из этих действий страница в админке сразу обновляет видимые статусы полей и source-подписи, показывая будущий результат.

Реальные изменения файлов по-прежнему происходят только после обычного `Save` в админке.

После `Перенести в config` и последующего `Save`:

- текущие значения из выбранного secrets-файла записываются обратно в plugin config YAML
- соответствующие записи удаляются из `user/secrets.yaml` / `user/secrets.<env>.yaml`
- статус поля меняется с `Хранится` на `Ожидает`
- source-подпись переключается на config YAML-файл

## Текущий scope

Надежно поддерживается:

- обычный plugin config save flow
- Flex configure flow, включая `algolia-pro`

Страница в админке показывает live preview состояний полей, но реальные изменения файлов по-прежнему применяются только после обычного `Save`.

## Эксплуатационные заметки

- `debug_logging` включает подробное логирование save, migrate и Flex flow
- env-specific storage используется только если Grav определил непустое имя environment

## Заметки для разработки

Внутренние детали интеграции с Grav/Admin/Flex, обходы и текущие ограничения вынесены сюда:

- [docs/admin-integration-notes.md](docs/admin-integration-notes.md) (англ. язык)

## Лицензия

MIT (см. `LICENSE`).
