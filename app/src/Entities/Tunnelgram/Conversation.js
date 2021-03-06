import {Nymph, Entity} from 'nymph-client';
import {User} from 'tilmeld-client';
import {Message} from './Message';
import {saveEntities, restoreEntities} from '../../Services/entityRefresh';
import {crypt} from '../../Services/EncryptionService';

let currentUser = null;

User.current().then(user => currentUser = user);
User.on('login', user => currentUser = user);
User.on('logout', () => currentUser = null);

export class Conversation extends Entity {
  // === Constructor ===

  constructor (id) {
    super(id);
    this.containsSleepingReference = false;
    this.pending = [];
    this.unreadCountPromise = null;
    this.unreadCountPromiseReadline = null;
    this.decrypted = {
      name: null
    };
    this.data.name = null;
    this.mode = Conversation.MODE_CONVERSATION;
    this.data.acFull = [];
  }

  // === Instance Methods ===

  init (entityData, ...args) {
    const savedEntities = saveEntities(this);
    super.init(entityData, ...args);
    this.containsSleepingReference = restoreEntities(this, savedEntities);

    if (entityData == null) {
      return this;
    }

    if (entityData.readline != null) {
      this.readline = entityData.readline;
    }

    // Decrypt the conversation name.
    if (this.data.name != null) {
      let decrypt = input => input;
      if (this.data.mode !== Conversation.MODE_CHANNEL_PUBLIC && currentUser && this.data.keys && this.data.keys.hasOwnProperty(currentUser.guid)) {
        const key = crypt.decryptRSA(this.data.keys[currentUser.guid]).slice(0, 96);
        decrypt = input => crypt.decrypt(input, key);
      }

      this.decrypted.name = decrypt(this.data.name);
    }

    this.unreadCountPromise = null;

    return this;
  }

  async save () {
    if (this.data.mode === Conversation.MODE_CHANNEL_PUBLIC) {
      this.data.name = this.decrypted.name;
      delete this.data.keys;
    } else {
      let key;

      if (this.decrypted.name != null || this.data.mode === Conversation.MODE_CHANNEL_PRIVATE) {
        key = crypt.generateKey();
        const encryptPromises = [];
        for (let user of this.data.acFull) {
          const pad = crypt.generatePad();
          encryptPromises.push({user, promise: crypt.encryptRSAForUser(key + pad, user)});
        }
        this.data.keys = {};
        for (let entry of encryptPromises) {
          this.data.keys[entry.user.guid] = await entry.promise;
        }
      }

      // Encrypt the conversation name for all recipients (which should include the current user).
      if (this.decrypted.name != null) {
        this.data.name = crypt.encrypt(this.decrypted.name, key);
      } else {
        this.data.name = null;
      }
    }

    return await super.save();
  }

  getName (settings) {
    if (this.guid == null) {
      return 'New Conversation';
    } else if (this.decrypted.name != null) {
      return this.decrypted.name;
    } else if (this.data.acFull.length === 1) {
      return 'Just You';
    } else {
      const names = [];
      for (let i = 0; i < this.data.acFull.length; i++) {
        const participant = this.data.acFull[i];
        if (!currentUser.is(participant)) {
          let name = participant.data.name ? participant.data.name : 'Loading...';
          if (settings && participant.guid in settings.decrypted.nicknames) {
            name = settings.decrypted.nicknames[participant.guid];
          }
          names.push(name);
        }
      }
      return names.join(', ');
    }
  }

  async unreadCount () {
    if (this.readline == null) {
      return true;
    }

    if (this.data.lastMessage) {
      await this.data.lastMessage.ready();
      if (this.data.lastMessage.cdate <= this.readline) {
        return 0;
      }
    }

    if (!this.unreadCountPromise || this.unreadCountPromiseReadline < this.readline) {
      this.unreadCountPromiseReadline = this.readline;
      this.unreadCountPromise = Nymph.getEntities({
        'class': Message.class,
        'return': 'guid'
      }, {
        'type': '&',
        'ref': ['conversation', this.guid],
        'gt': ['cdate', this.readline],
        '!strict': ['informational', true]
      });
    }

    return ((await this.unreadCountPromise) || []).length;
  }

  saveReadline (...args) {
    if (this.readline < args[0]) {
      this.readline = args[0];
      this.unreadCountPromise = null;
    }
    return this.serverCall('saveReadline', args, true);
  }

  clearReadline (...args) {
    this.readline = null;
    this.unreadCountPromise = null;
    return this.serverCall('clearReadline', args, true);
  }

  findMatchingConversations(...args) {
    return this.serverCall('findMatchingConversations', args, true);
  }
}

// === Static Properties ===

// The name of the server class
Conversation.class = 'Tunnelgram\\Conversation';
Conversation.MODE_CONVERSATION = 0;
Conversation.MODE_CHANNEL_PRIVATE = 1;
Conversation.MODE_CHANNEL_PUBLIC = 2;

Nymph.setEntityClass(Conversation.class, Conversation);

export default Conversation;
