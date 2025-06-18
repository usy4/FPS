# FPS
A simple FPS display plugin for PocketMine-MP servers.

## Features

- Real-time FPS display for players
- Multiple display modes (popup, tip, jukebox, scoreboard)
- Color customization
- ScoreHUD integration
- Resource pack support
- Per-player settings

## Requirements

- PocketMine-MP 5.0+
- FormAPI plugin
- libCustomPack plugin (optional, for resource pack features)
- ScoreHud plugin (optional, for scoreboard display)

## Installation

1. Download the plugin `.phar` file
2. Place it in your server's `plugins/` folder
3. Install required dependencies:
   - ScoreHud.phar (optional)
4. Restart your server

## Usage

### For Players
1. Enable "Client Diagnostics" in Minecraft settings:
   - Settings → Creator Settings → Enable Client Diagnostics
2. Use `/fps` command to configure display options

### For Server Owners
- The plugin works out of the box with default settings
- Players can customize their own display preferences
- Resource pack is automatically generated if libCustomPack is installed

## Commands

| Command | Permission | Description |
|---------|------------|-------------|
| `/fps` | `fps.command.fps` | Open FPS settings menu |

## Permissions

| Permission | Default | Description |
|------------|---------|-------------|
| `fps.command.fps` | `true` | Use the /fps command |
| `fps.admin` | `op` | Admin features |

## ScoreHUD Integration

Add this tag to your ScoreHUD scoreboards:
```yaml
lines:
  - "{fps.display}"
```

The tag will show "FPS: 60" with colors when enabled, or hide completely when disabled.

## Configuration

The plugin creates a simple config file with basic settings. Most configuration is done through the in-game `/fps` menu.

## Support

- Create an issue on GitHub for bugs or feature requests
- Make sure you have the required dependencies installed
- Check console logs for error messages

## License

MIT License
