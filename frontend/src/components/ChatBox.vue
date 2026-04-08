<template>
  <div class="flex flex-col h-full">
    <div class="p-3 border-b border-gray-700">
      <h3 class="font-semibold text-sm">Чат</h3>
    </div>
    
    <div ref="messagesContainer" class="flex-1 overflow-y-auto p-3 space-y-3">
      <div 
        v-for="msg in displayMessages" 
        :key="msg.id"
        class="text-sm"
        :class="{ 'text-cyan-400': msg.user_id === currentUserId }"
      >
        <div class="flex items-baseline gap-2">
          <span class="font-medium">{{ msg.user?.name || 'Anonymous' }}</span>
          <span class="text-xs text-gray-500">{{ formatTime(msg.created_at) }}</span>
        </div>
        <p class="text-gray-300">{{ msg.content }}</p>
      </div>
      
      <div v-if="displayMessages.length === 0" class="text-center text-gray-500 text-sm py-8">
        Пока нет сообщений
      </div>
    </div>
    
    <form @submit.prevent="sendMessage" class="p-3 border-t border-gray-700">
      <div class="flex gap-2">
        <input 
          v-model="newMessage" 
          type="text" 
          class="flex-1 px-3 py-2 bg-gray-700 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
          placeholder="Написать сообщение..."
          :disabled="!connected"
        />
        <button 
          type="submit" 
          class="px-4 py-2 bg-cyan-600 rounded-lg hover:bg-cyan-700 disabled:opacity-50"
          :disabled="!newMessage.trim() || !connected"
        >
          →
        </button>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/stores/auth'
import { useRoomStore } from '@/stores/room'
import type { ChatMessage } from '@/types'

const props = defineProps<{
  roomId: string
  isHost: boolean
}>()

const authStore = useAuthStore()
const roomStore = useRoomStore()

const messages = ref<ChatMessage[]>([])
const newMessage = ref('')
const messagesContainer = ref<HTMLElement | null>(null)

const connected = computed(() => roomStore.connected)
const currentUserId = computed(() => authStore.user?.id)

const displayMessages = computed(() => {
  return messages.value.slice(-50)
})

onMounted(async () => {
  await loadMessages()
  
  watch(() => roomStore.messages, (newMessages) => {
    const chatMessages = newMessages.filter(m => m.type === 'chat_message')
    chatMessages.forEach(msg => {
      if (msg.payload && !messages.value.find(m => m.id === msg.payload?.message_id)) {
        messages.value.push({
          id: msg.payload?.message_id || Date.now().toString(),
          content: msg.payload?.content || '',
          user: {
            id: msg.user_id || '',
            name: msg.payload?.user_name || 'Anonymous'
          },
          reply_to_id: null,
          created_at: new Date(msg.payload?.timestamp * 1000).toISOString()
        })
      }
    })
    scrollToBottom()
  }, { deep: true })
})

async function loadMessages() {
  try {
    const response = await axios.get(`/api/rooms/${props.roomId}/chat?limit=50`)
    messages.value = response.data.data.reverse()
    scrollToBottom()
  } catch (err) {
    console.error('Failed to load messages:', err)
  }
}

async function sendMessage() {
  if (!newMessage.value.trim()) return
  
  const messageId = Date.now().toString()
  
  messages.value.push({
    id: messageId,
    content: newMessage.value,
    user: {
      id: authStore.user?.id || '',
      name: authStore.user?.name || 'Вы'
    },
    reply_to_id: null,
    created_at: new Date().toISOString()
  })
  
  roomStore.sendChatMessage(newMessage.value, messageId)
  
  try {
    await axios.post(`/api/rooms/${props.roomId}/chat`, {
      content: newMessage.value
    })
  } catch (err) {
    console.error('Failed to send message:', err)
  }
  
  newMessage.value = ''
  scrollToBottom()
}

function scrollToBottom() {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

function formatTime(date: string) {
  return new Date(date).toLocaleTimeString('ru-RU', { 
    hour: '2-digit', 
    minute: '2-digit' 
  })
}

// HTML sanitization to prevent XSS
function sanitize(html: string): string {
  const div = document.createElement('div')
  div.textContent = html
  return div.innerHTML
}
</script>
