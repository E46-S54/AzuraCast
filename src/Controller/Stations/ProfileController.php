<?php
namespace App\Controller\Stations;

use App\Cache;
use App\Radio\Backend\BackendAbstract;
use App\Radio\Configuration;
use App\Radio\Frontend\FrontendAbstract;
use AzuraForms\Field\AbstractField;
use Doctrine\ORM\EntityManager;
use App\Entity;
use App\Http\Request;
use App\Http\Response;

class ProfileController
{
    /** @var EntityManager */
    protected $em;

    /** @var Cache */
    protected $cache;

    /** @var Configuration */
    protected $configuration;

    /** @var Entity\Repository\StationRepository */
    protected $station_repo;

    /** @var array */
    protected $form_config;

    /**
     * @param EntityManager $em
     * @param Cache $cache
     * @param Configuration $configuration
     * @param array $form_config
     * @see \App\Provider\StationsProvider
     */
    public function __construct(
        EntityManager $em,
        Cache $cache,
        Configuration $configuration,
        array $form_config
    )
    {
        $this->em = $em;
        $this->cache = $cache;
        $this->configuration = $configuration;
        $this->form_config = $form_config;

        $this->station_repo = $em->getRepository(Entity\Station::class);
    }

    public function indexAction(Request $request, Response $response): Response
    {
        $station = $request->getStation();
        $view = $request->getView();

        if (!$station->isEnabled()) {
            return $view->renderToResponse($response, 'stations/profile/disabled');
        }

        $backend = $request->getStationBackend();
        $frontend = $request->getStationFrontend();
        $remotes = $request->getStationRemotes();

        $stream_urls = [
            'local' => $frontend->getStreamUrls($station),
            'remote' => [],
        ];

        foreach($remotes as $ra_proxy) {
            $stream_urls['remote'][] = $ra_proxy->getAdapter()->getPublicUrl($ra_proxy->getRemote());
        }

        // Statistics about backend playback.
        $num_songs = $this->em->createQuery('SELECT COUNT(sm.id) FROM '.Entity\StationMedia::class.' sm LEFT JOIN sm.playlist_items spm LEFT JOIN spm.playlist sp WHERE sp.id IS NOT NULL AND sm.station_id = :station_id')
            ->setParameter('station_id', $station->getId())
            ->getSingleScalarResult();

        $num_playlists = $this->em->createQuery('SELECT COUNT(sp.id) FROM '.Entity\StationPlaylist::class.' sp WHERE sp.station_id = :station_id')
            ->setParameter('station_id', $station->getId())
            ->getSingleScalarResult();

        // Populate initial nowplaying data.
        $np = [
            'now_playing' => [
                'song' => [
                    'title' => __('Song Title'),
                    'artist' => __('Song Artist'),
                ],
                'is_request' => false,
                'elapsed' => 0,
                'duration' => 0,
            ],
            'listeners' => [
                'unique' => 0,
                'total' => 0,
            ],
            'live' => [
                'is_live' => false,
                'streamer_name' => '',
            ],
            'playing_next' => [
                'song' => [
                    'title' => __('Song Title'),
                    'artist' => __('Song Artist'),
                ],
            ],
        ];

        $station_np = $station->getNowplaying();
        if ($station_np instanceof Entity\Api\NowPlaying) {
            $np = array_intersect_key($station_np->toArray(), $np) + $np;
        }

        return $view->renderToResponse($response, 'stations/profile/index', [
            'num_songs' => $num_songs,
            'num_playlists' => $num_playlists,
            'stream_urls' => $stream_urls,
            'backend_type' => $station->getBackendType(),
            'backend_config' => (array)$station->getBackendConfig(),
            'backend_is_running' => $backend->isRunning($station),
            'frontend_type' => $station->getFrontendType(),
            'frontend_config' => (array)$station->getFrontendConfig(),
            'frontend_is_running' => $frontend->isRunning($station),
            'nowplaying' => $np,
        ]);
    }

    public function editAction(Request $request, Response $response, $station_id): Response
    {
        $station = $request->getStation();
        $frontend = $request->getStationFrontend();

        $base_form = $this->form_config;
        unset($base_form['groups']['admin']);

        $form = new \AzuraForms\Form($base_form);

        $port_checker = function($value) use ($station) {
            if (!empty($value)) {
                $value = (int)$value;
                $used_ports = $this->configuration->getUsedPorts($station);

                if (isset($used_ports[$value])) {
                    $station_reference = $used_ports[$value];
                    return __('This port is currently in use by the station "%s".', $station_reference['name']);
                }
            }
            return true;
        };

        foreach($form as $field) {
            /** @var AbstractField $field */
            $attrs = $field->getAttributes();

            if (isset($attrs['class']) && strpos($attrs['class'], 'input-port') !== false) {
                $field->addValidator($port_checker);
            }
        }

        $form->populate($this->station_repo->toArray($station));

        if (!empty($_POST) && $form->isValid($_POST)) {

            $data = $form->getValues();

            $old_frontend = $station->getFrontendType();
            $old_backend = $station->getBackendType();

            $this->station_repo->fromArray($station, $data);
            $this->em->persist($station);
            $this->em->flush();

            $frontend_changed = ($old_frontend !== $station->getFrontendType());
            $backend_changed = ($old_backend !== $station->getBackendType());
            $adapter_changed = $frontend_changed || $backend_changed;

            if ($frontend_changed) {
                $this->station_repo->resetMounts($station, $frontend);
            }

            $this->configuration->writeConfiguration($station, $adapter_changed);

            // Clear station cache.
            $this->cache->remove('stations');

            return $response->withRedirect($request->getRouter()->fromHere('stations:profile:index'));
        }

        return $request->getView()->renderToResponse($response, 'stations/profile/edit', [
            'form' => $form,
        ]);
    }
}
