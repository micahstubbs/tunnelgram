<?php namespace Tunnelgram;

use Nymph\Nymph;
use Tilmeld\Tilmeld;
use Respect\Validation\Validator as v;
use Ramsey\Uuid\Uuid;

class Message extends \Nymph\Entity {
  use SendPushNotificationsTrait;
  const ETYPE = 'message';
  protected $clientEnabledMethods = [];
  protected $whitelistData = [
    'text',
    'images',
    'video',
    'keys',
    'conversation',
    'acRead'
  ];
  protected $protectedTags = [];
  protected $whitelistTags = [];

  public function __construct($id = 0) {
    $this->images = [];
    $this->keys = [];
    parent::__construct($id);
  }

  public function handleDelete() {
    if ($this->is($this->conversation->lastMessage)) {
      $this->conversation->lastMessage = Nymph::getEntity([
        'class' => 'Tunnelgram\Message',
        'reverse' => true,
        'offset' => 1
      ], ['&',
        'ref' => ['conversation', $this->conversation]
      ]);
      $this->conversation->save();
    }
    // Delete images from blob store.
    if (count($this->images)) {
      include_once(__DIR__.'/../../Blob/BlobClient.php');
      $client = new BlobClient();
      foreach ($this->images as $curImg) {
        $client->delete('tunnelgram-thumbnails', $curImg['id']);
        $client->delete('tunnelgram-images', $curImg['id']);
      }
    }
    // Delete video from blob store.
    if ($this->video) {
      include_once(__DIR__.'/../../Blob/BlobClient.php');
      $client = new BlobClient();
      $client->delete('tunnelgram-thumbnails', $this->video['id']);
      $client->delete('tunnelgram-videos', $this->video['id']);
    }
    return true;
  }

  public function jsonSerialize($clientClassName = true) {
    $object = parent::jsonSerialize($clientClassName);

    if ($this->conversation->mode === Conversation::MODE_CHANNEL_PUBLIC) {
      $object->encryption = false;
    } else {
      if ($this->conversation->mode === Conversation::MODE_CHANNEL_PRIVATE) {
        $object->data['keys'] = $this->conversation->keys;
      }

      if (Tilmeld::$currentUser !== null) {
        $ownGuid = Tilmeld::$currentUser->guid;
        $newKeys = [];
        if (array_key_exists($ownGuid, $object->data['keys'])) {
          $newKeys[$ownGuid] = $object->data['keys'][$ownGuid];
        }
        $object->data['keys'] = $newKeys;
      }

      $object->encryption = true;
    }

    return $object;
  }

  public function save() {
    if (!Tilmeld::gatekeeper()) {
      // Only allow logged in users to save.
      return false;
    }

    if (!$this->conversation->guid) {
      return false;
    }

    if (!Tilmeld::checkPermissions($this->conversation, Tilmeld::FULL_ACCESS)) {
      return false;
    }

    if (isset($this->video) && count($this->images) > 0) {
      // Gotta be either images or video. Not both.
      return false;
    }

    if (!isset($this->guid)) {
      foreach ($this->images as &$curImg) {
        $curImg['id'] = Uuid::uuid4()->toString();
      }
      unset($curImg);

      if ($this->video) {
        $this->video['id'] = Uuid::uuid4()->toString();
      }
    }

    $recipientGuids = [];
    foreach ($this->acRead as $user) {
      $recipientGuids[] = $user->guid;
    }

    try {
      // Validate.
      v::notEmpty()
        ->attribute(
            'keys',
            v::arrayVal()->each(
                v::stringType()->notEmpty()->prnt()->length(1, 2048),
                v::intVal()->in($recipientGuids)
            )
        )
        ->when(
            v::attribute('text', v::nullType(), false),
            v::oneOf(
              v::attribute('images', v::notEmpty()),
              v::attribute('video', v::notEmpty())
            ),
            v::attribute(
                'text',
                v::stringType()->notEmpty()->prnt()->length(
                    1,
                    ceil(4096 * 4 / 3) // Base64 of 4KiB
                )
            )
        )
        ->attribute(
            'images',
            v::arrayVal()->length(null, 9)->each(
                v::arrayVal()->length(10, 10)->keySet(
                    v::key(
                        'id',
                        v::regex('/'.Uuid::VALID_PATTERN.'/')
                    ),
                    v::key(
                        'name',
                        v::stringType()->notEmpty()->prnt()->length(
                            1,
                            ceil(2048 * 4 / 3) // Base64 of 2KiB
                        )
                    ),
                    v::key(
                        'thumbnail',
                        v::stringType()->notEmpty()->prnt()->length(
                            1,
                            ceil(102400 * 4 / 3) // Base64 of 100KiB
                        )
                    ),
                    v::key(
                        'thumbnailType',
                        v::stringType()->notEmpty()->prnt()->length(1, 50)
                    ),
                    v::key(
                        'thumbnailWidth',
                        v::stringType()->notEmpty()->prnt()->length(1, 50)
                    ),
                    v::key(
                        'thumbnailHeight',
                        v::stringType()->notEmpty()->prnt()->length(1, 50)
                    ),
                    v::key(
                        'data',
                        v::stringType()->notEmpty()->prnt()->length(
                            1,
                            ceil(2097152 * 4 / 3) // Base64 of 2MiB
                        )
                    ),
                    v::key(
                        'dataType',
                        v::stringType()->notEmpty()->prnt()->length(1, 50)
                    ),
                    v::key(
                        'dataWidth',
                        v::stringType()->notEmpty()->prnt()->length(1, 50)
                    ),
                    v::key(
                        'dataHeight',
                        v::stringType()->notEmpty()->prnt()->length(1, 50)
                    )
                )
            )
        )
        ->attribute(
            'video',
            v::oneOf(
              v::nullType(),
              v::arrayVal()->length(11, 11)->keySet(
                  v::key(
                      'id',
                      v::regex('/'.Uuid::VALID_PATTERN.'/')
                  ),
                  v::key(
                      'name',
                      v::stringType()->notEmpty()->prnt()->length(
                          1,
                          ceil(2048 * 4 / 3) // Base64 of 2KiB
                      )
                  ),
                  v::key(
                      'thumbnail',
                      v::stringType()->notEmpty()->prnt()->length(
                          1,
                          ceil(409600 * 4 / 3) // Base64 of 400KiB
                      )
                  ),
                  v::key(
                      'thumbnailType',
                      v::stringType()->notEmpty()->prnt()->length(1, 50)
                  ),
                  v::key(
                      'thumbnailWidth',
                      v::stringType()->notEmpty()->prnt()->length(1, 50)
                  ),
                  v::key(
                      'thumbnailHeight',
                      v::stringType()->notEmpty()->prnt()->length(1, 50)
                  ),
                  v::key(
                      'data',
                      v::stringType()->notEmpty()->prnt()->length(
                          1,
                          ceil(20971520 * 4 / 3) // Base64 of 20MiB
                      )
                  ),
                  v::key(
                      'dataType',
                      v::stringType()->notEmpty()->prnt()->length(1, 50)
                  ),
                  v::key(
                      'dataWidth',
                      v::stringType()->notEmpty()->prnt()->length(1, 50)
                  ),
                  v::key(
                      'dataHeight',
                      v::stringType()->notEmpty()->prnt()->length(1, 50)
                  ),
                  v::key(
                      'dataDuration',
                      v::stringType()->notEmpty()->prnt()->length(1, 50)
                  )
              )
            ),
            false
        )
        ->attribute('conversation', v::instance('Tunnelgram\Conversation'))
        ->setName('message')
        ->assert($this->getValidatable());

      // Upload images to blob store.
      if (!isset($this->guid) && count($this->images)) {
        include(__DIR__.'/../../Blob/BlobClient.php');
        $client = new BlobClient();
        foreach ($this->images as &$curImg) {
          $curImg['thumbnail'] = $client->upload(
              'tunnelgram-thumbnails',
              $curImg['id'],
              base64_decode($curImg['thumbnail'])
          );
          $curImg['data'] = $client->upload(
              'tunnelgram-images',
              $curImg['id'],
              base64_decode($curImg['data'])
          );
        }
        unset($curImg);
      }

      // Upload video to blob store.
      if (!isset($this->guid) && $this->video) {
        include(__DIR__.'/../../Blob/BlobClient.php');
        $client = new BlobClient();
        $this->video['thumbnail'] = $client->upload(
            'tunnelgram-thumbnails',
            $this->video['id'],
            base64_decode($this->video['thumbnail'])
        );
        $this->video['data'] = $client->upload(
            'tunnelgram-videos',
            $this->video['id'],
            base64_decode($this->video['data'])
        );
      }
    } catch (\Respect\Validation\Exceptions\NestedValidationException $exception) {
      throw new \Exception($exception->getFullMessage());
    }
    $ret = parent::save();

    if ($ret) {
      $this->conversation->refresh();
      $this->conversation->lastMessage = $this;
      $this->conversation->save();

      if (count($recipientGuids) > 1) {
        $showNameProp = count($this->conversation->acFull) > 2 ? 'nameFirst' : 'name';
        $names = [];
        foreach ($this->conversation->acFull as $curUser) {
          $names[$curUser->guid] = $curUser->$showNameProp;
        }
        // Send push notifications to the recipients after script execution.
        register_shutdown_function(
            [$this, 'sendPushNotifications'],
            array_diff($recipientGuids, [Tilmeld::$currentUser->guid]),
            [
              'conversationGuid' => $this->conversation->guid,
              'senderName' => Tilmeld::$currentUser->name,
              'names' => $names,
              'type' => 'message',
              'messageType' => $this->images
                ? 'Photo'
                : ($this->video ? 'Video' : 'Message')
            ]
        );
      }

      // Update the user's readline.
      $this->conversation->saveReadline($this->cdate);
    }

    return $ret;
  }
}
