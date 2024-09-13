// @ts-nocheck
import Pusher from 'pusher-js';

declare module 'laravel-echo/src/channel/null-channel' {
  import { Channel } from './channel';

  /**
   * This class represents a null channel.
   */
  export class NullChannel extends Channel {
    subscribe(): any;
    unsubscribe(): void;
    listen(event: string, callback: Function): NullChannel;
    listenToAll(callback: Function): NullChannel;
    stopListening(event: string, callback?: Function): NullChannel;
    subscribed(callback: Function): NullChannel;
    error(callback: Function): NullChannel;
    on(event: string, callback: Function): NullChannel;
  }
}

declare module 'laravel-echo/src/channel/null-presence-channel' {
  import { NullChannel } from './null-channel';

  /**
   * This class represents a null presence channel.
   */
  export class NullPresenceChannel extends NullChannel {
    here(callback: Function): NullPresenceChannel;
    joining(callback: Function): NullPresenceChannel;
    leaving(callback: Function): NullPresenceChannel;
  }
}

declare module 'laravel-echo/src/channel/null-private-channel' {
  import { NullChannel } from './null-channel';

  /**
   * This class represents a null private channel.
   */
  export class NullPrivateChannel extends NullChannel {
    whisper(eventName: string, data: any): NullPrivateChannel;
  }
}

declare module 'laravel-echo/src/channel/pusher-channel' {
  import { EventFormatter } from '../util';
  import { Channel } from './channel';

  export class PusherChannel extends Channel {
    pusher: Pusher;
    name: any;
    options: any;
    eventFormatter: EventFormatter;
    subscription: any;

    constructor(pusher: Pusher, name: any, options: any);
    subscribe(): any;
    unsubscribe(): void;
    listen(event: string, callback: Function): PusherChannel;
    listenToAll(callback: Function): PusherChannel;
    stopListening(event: string, callback?: Function): PusherChannel;
    stopListeningToAll(callback?: Function): PusherChannel;
    subscribed(callback: Function): PusherChannel;
    error(callback: Function): PusherChannel;
    on(event: string, callback: Function): PusherChannel;
  }
}

declare module 'laravel-echo/src/channel/pusher-presence-channel' {
  import { PusherChannel } from './pusher-channel';

  export class PusherPresenceChannel extends PusherChannel {
    here(callback: Function): PusherPresenceChannel;
    joining(callback: Function): PusherPresenceChannel;
    leaving(callback: Function): PusherPresenceChannel;
  }
}

declare module 'laravel-echo/src/channel/pusher-private-channel' {
  import { PusherChannel } from './pusher-channel';

  /**
   * This class represents a Pusher private channel.
   */
  export class PusherPrivateChannel extends PusherChannel {
    whisper(eventName: string, data: any): PusherPrivateChannel;
  }
}

declare module 'laravel-echo/src/channel/pusher-encrypted-private-channel' {
  import { PusherChannel } from './pusher-channel';

  /**
   * This class represents a Pusher encrypted private channel.
   */
  export class PusherEncryptedPrivateChannel extends PusherChannel {
    whisper(eventName: string, data: any): PusherEncryptedPrivateChannel;
  }
}

declare module 'laravel-echo/src/channel/socketio-channel' {
  import { Channel } from './channel';
  import { EventFormatter } from '../util';

  export class SocketIoChannel extends Channel {
    socket: any;
    name: string;
    options: any;
    eventFormatter: EventFormatter;
    subscription: any;

    constructor(socket: any, name: string, options: any);
    subscribe(): any;
    unsubscribe(): void;
    listen(event: string, callback: Function): SocketIoChannel;
    stopListening(event: string, callback?: Function): SocketIoChannel;
    subscribed(callback: Function): SocketIoChannel;
    error(callback: Function): SocketIoChannel;
    on(event: string, callback: Function): SocketIoChannel;
  }
}

declare module 'laravel-echo/src/channel/socketio-presence-channel' {
  import { SocketIoChannel } from './socketio-channel';

  export class SocketIoPresenceChannel extends SocketIoChannel {
    here(callback: Function): SocketIoPresenceChannel;
    joining(callback: Function): SocketIoPresenceChannel;
    leaving(callback: Function): SocketIoPresenceChannel;
  }
}

declare module 'laravel-echo/src/connector/connector' {
  import { Channel, PresenceChannel } from './../channel';

  export abstract class Connector {
    private _defaultOptions: any;
    options: any;

    constructor(options: any);

    protected setOptions(options: any): any;
    protected csrfToken(): null | string;

    abstract connect(): void;
    abstract channel(channel: string): Channel;
    abstract privateChannel(channel: string): Channel;
    abstract presenceChannel(channel: string): PresenceChannel;
    abstract leave(channel: string): void;
    abstract leaveChannel(channel: string): void;
    abstract socketId(): string;
    abstract disconnect(): void;
  }
}

declare module 'laravel-echo/src/channel/presence-channel' {
  import { Channel } from './channel';

  /**
   * This interface represents a presence channel.
   */
  export interface PresenceChannel extends Channel {
    here(callback: Function): PresenceChannel;
    joining(callback: Function): PresenceChannel;
    whisper(eventName: string, data: any): PresenceChannel;
    leaving(callback: Function): PresenceChannel;
  }
}

declare module 'laravel-echo' {
  export default class Echo {
    connector: PusherConnector;
  }

  export class PusherConnector extends Connector {
    /**
     * The Pusher instance.
     */
    pusher: Pusher;

    /**
     * All of the subscribed channel names.
     */
    channels: any = {};

    /**
     * Create a fresh Pusher connection.
     */
    connect(): void {
      if (typeof this.options.client !== 'undefined') {
        this.pusher = this.options.client;
      } else if (this.options.Pusher) {
        this.pusher = new this.options.Pusher(this.options.key, this.options);
      } else {
        this.pusher = new Pusher(this.options.key, this.options);
      }
    }

    /**
     * Sign in the user via Pusher user authentication (https://pusher.com/docs/channels/using_channels/user-authentication/).
     */
    signin(): void {
      this.pusher.signin();
    }

    /**
     * Listen for an event on a channel instance.
     */
    listen(name: string, event: string, callback: Function): PusherChannel {
      return this.channel(name).listen(event, callback);
    }

    /**
     * Get a channel instance by name.
     */
    channel(name: string): PusherChannel {
      if (!this.channels[name]) {
        this.channels[name] = new PusherChannel(this.pusher, name, this.options);
      }

      return this.channels[name];
    }

    /**
     * Get a private channel instance by name.
     */
    privateChannel(name: string): PusherChannel {
      if (!this.channels['private-' + name]) {
        this.channels['private-' + name] = new PusherPrivateChannel(this.pusher, 'private-' + name, this.options);
      }

      return this.channels['private-' + name];
    }

    /**
     * Get a private encrypted channel instance by name.
     */
    encryptedPrivateChannel(name: string): PusherChannel {
      if (!this.channels['private-encrypted-' + name]) {
        this.channels['private-encrypted-' + name] = new PusherEncryptedPrivateChannel(
          this.pusher,
          'private-encrypted-' + name,
          this.options
        );
      }

      return this.channels['private-encrypted-' + name];
    }

    /**
     * Get a presence channel instance by name.
     */
    presenceChannel(name: string): PresenceChannel {
      if (!this.channels['presence-' + name]) {
        this.channels['presence-' + name] = new PusherPresenceChannel(
          this.pusher,
          'presence-' + name,
          this.options
        );
      }

      return this.channels['presence-' + name];
    }

    /**
     * Leave the given channel, as well as its private and presence variants.
     */
    leave(name: string): void {
      let channels = [name, 'private-' + name, 'private-encrypted-' + name, 'presence-' + name];

      channels.forEach((name: string, index: number) => {
        this.leaveChannel(name);
      });
    }

    /**
     * Leave the given channel.
     */
    leaveChannel(name: string): void {
      if (this.channels[name]) {
        this.channels[name].unsubscribe();

        delete this.channels[name];
      }
    }

    /**
     * Get the socket ID for the connection.
     */
    socketId(): string {
      return this.pusher.connection.socket_id;
    }

    /**
     * Disconnect Pusher connection.
     */
    disconnect(): void {
      this.pusher.disconnect();
    }
  }

}