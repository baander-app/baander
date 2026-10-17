import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FakeIndexedDb, installIndexedDb } from '../../../../tests/shared/auth/auth-fakes'
import {
  beginStoredLogout,
  clearStoredAuth,
  listPendingLogouts,
  loadStoredAuth,
  removePendingLogout,
  saveStoredAuth,
  updatePendingLogout,
} from '../auth-db'

const STORE = 'auth'

const user = {
  email: 'listener@baander.app',
  publicId: 'usr_listener',
  name: 'Listener',
  roles: ['ROLE_USER'],
}

function credentials(accessToken: string, refreshToken = `${accessToken}-refresh`) {
  return {
    cryptoKeyPair: { publicKey: { kid: 'pub' } as unknown as CryptoKey, privateKey: { kid: 'priv' } as unknown as CryptoKey },
    publicJwk: { kty: 'EC' } as JsonWebKey,
    jkt: 'jkt-1',
    accessToken,
    refreshToken,
    user,
    nonce: 'nonce-1',
  }
}

let db: FakeIndexedDb

beforeEach(() => {
  db = installIndexedDb().db
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('saveStoredAuth', () => {
  it('writes the first record at epoch 1 when nothing is stored', async () => {
    await expect(saveStoredAuth(credentials('access-1'), null)).resolves.toEqual({ ok: true, epoch: 1 })

    await expect(loadStoredAuth()).resolves.toMatchObject({
      epoch: 1,
      accessToken: 'access-1',
      refreshToken: 'access-1-refresh',
      jkt: 'jkt-1',
      nonce: 'nonce-1',
      user,
    })
  })

  it('compares and puts inside one readwrite transaction', async () => {
    await saveStoredAuth(credentials('access-1'), null)
    db.log.length = 0

    await expect(saveStoredAuth(credentials('access-2'), 1)).resolves.toEqual({ ok: true, epoch: 2 })

    expect(db.log).toEqual([
      { transaction: expect.any(Number), mode: 'readwrite', operation: 'get', key: 'current' },
      { transaction: expect.any(Number), mode: 'readwrite', operation: 'put', key: 'current' },
    ])
    expect(db.log[0].transaction).toBe(db.log[1].transaction)
  })

  it('resolves with the stored record and writes nothing when the epoch moved on', async () => {
    await saveStoredAuth(credentials('access-1'), null)
    await saveStoredAuth(credentials('access-2'), 1)
    db.log.length = 0

    const result = await saveStoredAuth(credentials('stale'), 1)

    expect(result).toMatchObject({ ok: false, current: { epoch: 2, accessToken: 'access-2' } })
    expect(db.log.map((entry) => entry.operation)).toEqual(['get'])
    expect(db.records(STORE).get('current')).toMatchObject({ epoch: 2, accessToken: 'access-2' })
  })

  it('lets exactly one of two concurrent writers at the same epoch win', async () => {
    await saveStoredAuth(credentials('access-1'), null)

    const [first, second] = await Promise.all([
      saveStoredAuth(credentials('from-tab-a'), 1),
      saveStoredAuth(credentials('from-tab-b'), 1),
    ])

    expect(first).toEqual({ ok: true, epoch: 2 })
    expect(second).toMatchObject({ ok: false, current: { epoch: 2, accessToken: 'from-tab-a' } })
    expect(db.records(STORE).get('current')).toMatchObject({ epoch: 2, accessToken: 'from-tab-a' })
  })

  it('fails against the tombstone a clear left behind', async () => {
    await saveStoredAuth(credentials('access-1'), null)
    await clearStoredAuth()

    const result = await saveStoredAuth(credentials('late-rotation'), 1)

    expect(result).toEqual({ ok: false, current: { epoch: 2, cleared: true } })
    expect(db.records(STORE).get('current')).toEqual({ id: 'current', epoch: 2, cleared: true })
  })

  it('writes a login over the tombstone at its epoch plus one', async () => {
    await saveStoredAuth(credentials('access-1'), null)
    await clearStoredAuth()

    await expect(saveStoredAuth(credentials('after-login'), 2)).resolves.toEqual({ ok: true, epoch: 3 })
    await expect(loadStoredAuth()).resolves.toMatchObject({ epoch: 3, accessToken: 'after-login' })
  })
})

describe('clearStoredAuth', () => {
  it('leaves a tombstone with the next epoch and no keys or tokens', async () => {
    await saveStoredAuth(credentials('access-1'), null)

    await expect(clearStoredAuth()).resolves.toBe(2)

    expect(db.records(STORE).get('current')).toEqual({ id: 'current', epoch: 2, cleared: true })
    await expect(loadStoredAuth()).resolves.toEqual({ epoch: 2, cleared: true })
  })

  it('starts the epoch at 1 when nothing was stored', async () => {
    await expect(clearStoredAuth()).resolves.toBe(1)
    await expect(loadStoredAuth()).resolves.toEqual({ epoch: 1, cleared: true })
  })
})

describe('loadStoredAuth', () => {
  it('returns null for an empty store', async () => {
    await expect(loadStoredAuth()).resolves.toBeNull()
  })

  it('wipes a record that has no epoch', async () => {
    db.seed(STORE, { id: 'current', ...credentials('pre-epoch') })

    await expect(loadStoredAuth()).resolves.toBeNull()

    expect(db.records(STORE).has('current')).toBe(false)
  })
})

describe('pending logouts', () => {
  function pendingEntry(id: string, createdAt = 1_000) {
    return {
      id,
      createdAt,
      keyPair: {
        publicKey: { kid: `pub-${id}` } as unknown as CryptoKey,
        privateKey: { kid: `priv-${id}` } as unknown as CryptoKey,
        jwk: { kty: 'EC', x: id } as JsonWebKey,
        jkt: `jkt-${id}`,
      },
      accessToken: `access-${id}`,
      refreshToken: `refresh-${id}`,
      accessTokenExpiresAt: 2_000,
      origin: 'https://api.baander.app',
      attempts: 0,
    }
  }

  it('writes the entry and the tombstone in one readwrite transaction and resolves the epoch', async () => {
    await saveStoredAuth(credentials('access-1'), null)
    db.log.length = 0

    await expect(beginStoredLogout(pendingEntry('a'))).resolves.toBe(2)

    const transactions = new Set(db.log.map((entry) => entry.transaction))
    expect(transactions.size).toBe(1)
    expect(db.log.map((entry) => [entry.mode, entry.operation, entry.key])).toEqual([
      ['readwrite', 'get', 'current'],
      ['readwrite', 'get', 'pending-logouts'],
      ['readwrite', 'put', 'pending-logouts'],
      ['readwrite', 'put', 'current'],
    ])
    expect(db.records(STORE).get('current')).toEqual({ id: 'current', epoch: 2, cleared: true })
    await expect(loadStoredAuth()).resolves.toEqual({ epoch: 2, cleared: true })
    await expect(listPendingLogouts()).resolves.toEqual([pendingEntry('a')])
  })

  it('keeps the key pair, the tokens, the expiry, the origin and the attempt count of every entry', async () => {
    await beginStoredLogout(pendingEntry('a', 1_000))
    await beginStoredLogout(pendingEntry('b', 2_000))

    await expect(listPendingLogouts()).resolves.toEqual([pendingEntry('a', 1_000), pendingEntry('b', 2_000)])
  })

  it('keeps both entries when two tabs append at the same time', async () => {
    await Promise.all([beginStoredLogout(pendingEntry('a')), beginStoredLogout(pendingEntry('b'))])

    const ids = (await listPendingLogouts()).map((entry) => entry.id)
    expect(ids.sort()).toEqual(['a', 'b'])
  })

  it('loses nothing when a delete runs beside an append', async () => {
    await beginStoredLogout(pendingEntry('a'))

    await Promise.all([removePendingLogout('a'), beginStoredLogout(pendingEntry('b'))])

    expect((await listPendingLogouts()).map((entry) => entry.id)).toEqual(['b'])
  })

  it('updates one entry in a readwrite transaction that re-reads the list', async () => {
    await beginStoredLogout(pendingEntry('a'))
    await beginStoredLogout(pendingEntry('b'))
    db.log.length = 0

    await expect(updatePendingLogout('a', { accessToken: 'access-a2', refreshToken: 'refresh-a2', accessTokenExpiresAt: 9_000, attempts: 1 })).resolves.toBe(true)

    expect(db.log.map((entry) => [entry.mode, entry.operation, entry.key])).toEqual([
      ['readwrite', 'get', 'pending-logouts'],
      ['readwrite', 'put', 'pending-logouts'],
    ])
    expect(db.log[0].transaction).toBe(db.log[1].transaction)
    await expect(listPendingLogouts()).resolves.toEqual([
      { ...pendingEntry('a'), accessToken: 'access-a2', refreshToken: 'refresh-a2', accessTokenExpiresAt: 9_000, attempts: 1 },
      pendingEntry('b'),
    ])
  })

  it('resolves false from an update of an entry that is gone and writes nothing', async () => {
    await beginStoredLogout(pendingEntry('a'))
    db.log.length = 0

    await expect(updatePendingLogout('missing', { attempts: 1 })).resolves.toBe(false)

    expect(db.log.map((entry) => entry.operation)).toEqual(['get'])
  })

  it('removes one entry and leaves the others', async () => {
    await beginStoredLogout(pendingEntry('a'))
    await beginStoredLogout(pendingEntry('b'))

    await removePendingLogout('a')

    expect((await listPendingLogouts()).map((entry) => entry.id)).toEqual(['b'])
    await removePendingLogout('a')
    expect((await listPendingLogouts()).map((entry) => entry.id)).toEqual(['b'])
  })

  it('lists nothing when no logout was ever recorded', async () => {
    await expect(listPendingLogouts()).resolves.toEqual([])
  })

  it('never touches the pending list on a save, a clear or a load', async () => {
    await beginStoredLogout(pendingEntry('a'))
    db.log.length = 0

    await saveStoredAuth(credentials('after-login'), 1)
    await loadStoredAuth()
    await clearStoredAuth()

    expect(db.log.every((entry) => entry.key === 'current')).toBe(true)
    expect((await listPendingLogouts()).map((entry) => entry.id)).toEqual(['a'])
  })
})
